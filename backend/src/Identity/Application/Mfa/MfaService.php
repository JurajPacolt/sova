<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Mfa;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use SensitiveParameter;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Authentication\UserCredentials;
use Sova\Identity\Application\Authentication\UserCredentialsRepository;
use Sova\Identity\Application\Authentication\UserSessionRepository;
use Sova\Identity\Application\Security\PasswordHasher;
use Sova\Identity\Domain\User\UserStatus;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Application\Security\SensitivePayloadCipher;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class MfaService
{
    private const RECOVERY_CODE_COUNT = 10;
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private bool $superadminMfaRequired;
    private string $issuer;

    public function __construct(
        private Connection $connection,
        private MfaCredentialRepository $credentials,
        private UserCredentialsRepository $users,
        private UserSessionRepository $sessions,
        private PasswordHasher $passwordHasher,
        private SensitivePayloadCipher $cipher,
        private TotpAuthenticator $totp,
        private SecurityAuditRecorder $audit,
        Settings $settings,
    ) {
        $this->superadminMfaRequired = $settings->string(
            'app.environment',
            'production',
        ) === 'production';
        $this->issuer = $settings->string('app.name', 'SOVA');
    }

    public function verifyLogin(
        string $userId,
        #[SensitiveParameter]
        ?string $code,
        string $requestId,
        ?string $ipAddress,
    ): MfaLoginVerification {
        $credential = $this->credentials->find($userId);

        if ($credential === null || !$credential->isEnabled()) {
            return new MfaLoginVerification(
                enabled: false,
                verifiedAt: null,
                recoveryCodesRemaining: 0,
            );
        }

        if ($code === null || trim($code) === '') {
            throw new DomainProblemException(
                ProblemType::AuthenticationRequired,
                'MFA_CODE_REQUIRED',
                'A multi-factor authentication code is required.',
            );
        }

        $now = $this->now();

        return $this->connection->transactional(
            function () use (
                $userId,
                $code,
                $requestId,
                $ipAddress,
                $now,
            ): MfaLoginVerification {
                $credential = $this->credentials->findForUpdate($userId);

                if ($credential === null || !$credential->isEnabled()) {
                    throw new DomainProblemException(
                        ProblemType::AuthenticationRequired,
                        'MFA_CODE_INVALID',
                        'The multi-factor authentication code is invalid.',
                    );
                }

                $usedRecoveryCode = $this->verifyCredential(
                    $credential,
                    $code,
                    $now,
                );
                $updated = $this->credentials->findForUpdate($userId);

                if ($updated === null) {
                    throw new RuntimeException(
                        'The MFA credential disappeared during verification.',
                    );
                }

                if ($usedRecoveryCode) {
                    $this->audit->record(
                        eventType: 'MFA_RECOVERY_CODE_USED',
                        outcome: 'SUCCESS',
                        reasonCode: 'MFA_RECOVERY_CODE_USED',
                        requestId: $requestId,
                        actorUserId: $userId,
                        ipAddress: $ipAddress,
                        metadata: [
                            'recovery_codes_remaining' => count(
                                $updated->recoveryCodeHashes,
                            ),
                        ],
                    );
                }

                return new MfaLoginVerification(
                    enabled: true,
                    verifiedAt: $now,
                    recoveryCodesRemaining: count(
                        $updated->recoveryCodeHashes,
                    ),
                    usedRecoveryCode: $usedRecoveryCode,
                );
            },
        );
    }

    public function sessionStatus(
        bool $hasSuperadminRole,
        MfaLoginVerification $verification,
    ): MfaSessionStatus {
        return new MfaSessionStatus(
            enabled: $verification->enabled,
            verified: $verification->isVerified(),
            enrollmentRequired: $hasSuperadminRole
                && $this->superadminMfaRequired
                && !$verification->enabled,
            recoveryCodesRemaining: $verification->recoveryCodesRemaining,
        );
    }

    public function canUseSuperadmin(
        bool $hasSuperadminRole,
        MfaLoginVerification $verification,
    ): bool {
        if (!$hasSuperadminRole) {
            return false;
        }

        if ($verification->enabled) {
            return $verification->isVerified();
        }

        return !$this->superadminMfaRequired;
    }

    public function status(SessionContext $session): MfaSessionStatus
    {
        $credential = $this->credentials->find($session->actorUserId);
        $enabled = $credential?->isEnabled() ?? false;

        return new MfaSessionStatus(
            enabled: $enabled,
            verified: $session->mfaVerified,
            enrollmentRequired: $session->actorHasSuperadminRole
                && $this->superadminMfaRequired
                && !$enabled,
            recoveryCodesRemaining: $enabled
                ? count($credential->recoveryCodeHashes)
                : 0,
        );
    }

    public function beginEnrollment(
        SessionContext $session,
        #[SensitiveParameter]
        string $currentPassword,
        string $requestId,
        ?string $ipAddress,
    ): MfaEnrollment {
        $user = $this->reauthenticate($session, $currentPassword);
        $secret = $this->totp->generateSecret();
        $encrypted = $this->cipher->encrypt(['totp_secret' => $secret]);

        $this->connection->transactional(function () use (
            $session,
            $encrypted,
            $requestId,
            $ipAddress,
        ): void {
            $credential = $this->credentials->findForUpdate(
                $session->actorUserId,
            );

            if ($credential?->isEnabled() === true) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'MFA_ALREADY_ENABLED',
                    'Multi-factor authentication is already enabled.',
                );
            }

            $this->credentials->replacePending(
                $session->actorUserId,
                $encrypted->keyId,
                $encrypted->ciphertext,
            );
            $this->audit->record(
                eventType: 'MFA_ENROLLMENT_STARTED',
                outcome: 'SUCCESS',
                reasonCode: 'MFA_ENROLLMENT_STARTED',
                requestId: $requestId,
                actorUserId: $session->actorUserId,
                ipAddress: $ipAddress,
            );
        });

        return new MfaEnrollment(
            secret: $secret,
            otpauthUri: $this->totp->provisioningUri(
                $secret,
                $user->email,
                $this->issuer,
            ),
        );
    }

    public function confirmEnrollment(
        SessionContext $session,
        #[SensitiveParameter]
        string $code,
        string $requestId,
        ?string $ipAddress,
    ): MfaConfirmation {
        $this->assertDirectSession($session);
        $now = $this->now();

        return $this->connection->transactional(function () use (
            $session,
            $code,
            $requestId,
            $ipAddress,
            $now,
        ): MfaConfirmation {
            $credential = $this->credentials->findForUpdate(
                $session->actorUserId,
            );

            if ($credential === null) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'MFA_ENROLLMENT_NOT_STARTED',
                    'Start multi-factor authentication enrollment first.',
                );
            }

            if ($credential->isEnabled()) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'MFA_ALREADY_ENABLED',
                    'Multi-factor authentication is already enabled.',
                );
            }

            $secret = $this->decryptSecret($credential);
            $step = $this->totp->verify($secret, trim($code), $now);

            if ($step === null) {
                throw new DomainProblemException(
                    ProblemType::AuthenticationRequired,
                    'MFA_CODE_INVALID',
                    'The multi-factor authentication code is invalid.',
                );
            }

            $recoveryCodes = $this->generateRecoveryCodes();
            $hashes = array_map(
                $this->hashRecoveryCode(...),
                $recoveryCodes,
            );

            if (!$this->credentials->enable(
                $session->actorUserId,
                $now,
                $hashes,
                $step,
            )) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'MFA_ENROLLMENT_CHANGED',
                    'The multi-factor authentication enrollment changed.',
                );
            }

            $this->sessions->markMfaVerified(
                $session->sessionId,
                $session->actorUserId,
                $now,
            );
            $this->sessions->revokeOtherForUser(
                $session->actorUserId,
                $session->sessionId,
                'MFA_ENABLED',
            );
            $this->audit->record(
                eventType: 'MFA_ENABLED',
                outcome: 'SUCCESS',
                reasonCode: 'MFA_ENABLED',
                requestId: $requestId,
                actorUserId: $session->actorUserId,
                ipAddress: $ipAddress,
                metadata: [
                    'recovery_codes_issued' => count($recoveryCodes),
                ],
            );

            return new MfaConfirmation($recoveryCodes);
        });
    }

    public function regenerateRecoveryCodes(
        SessionContext $session,
        #[SensitiveParameter]
        string $currentPassword,
        #[SensitiveParameter]
        string $code,
        string $requestId,
        ?string $ipAddress,
    ): MfaConfirmation {
        $this->reauthenticate($session, $currentPassword);
        $now = $this->now();

        return $this->connection->transactional(function () use (
            $session,
            $code,
            $requestId,
            $ipAddress,
            $now,
        ): MfaConfirmation {
            $credential = $this->enabledCredentialForUpdate(
                $session->actorUserId,
            );
            $this->verifyCredential($credential, $code, $now);
            $recoveryCodes = $this->generateRecoveryCodes();
            $hashes = array_map(
                $this->hashRecoveryCode(...),
                $recoveryCodes,
            );
            $updated = $this->credentials->findForUpdate(
                $session->actorUserId,
            );

            if ($updated === null) {
                throw new RuntimeException(
                    'The MFA credential disappeared during verification.',
                );
            }

            $this->credentials->updateVerificationState(
                $session->actorUserId,
                $updated->lastUsedStep,
                $hashes,
            );
            $this->audit->record(
                eventType: 'MFA_RECOVERY_CODES_REGENERATED',
                outcome: 'SUCCESS',
                reasonCode: 'MFA_RECOVERY_CODES_REGENERATED',
                requestId: $requestId,
                actorUserId: $session->actorUserId,
                ipAddress: $ipAddress,
                metadata: [
                    'recovery_codes_issued' => count($recoveryCodes),
                ],
            );

            return new MfaConfirmation($recoveryCodes);
        });
    }

    public function disable(
        SessionContext $session,
        #[SensitiveParameter]
        string $currentPassword,
        #[SensitiveParameter]
        string $code,
        string $requestId,
        ?string $ipAddress,
    ): void {
        $this->reauthenticate($session, $currentPassword);

        if (
            $session->actorHasSuperadminRole
            && $this->superadminMfaRequired
        ) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'MFA_REQUIRED_FOR_SUPERADMIN',
                'Production superadministrators cannot disable multi-factor authentication.',
            );
        }

        $now = $this->now();
        $this->connection->transactional(function () use (
            $session,
            $code,
            $requestId,
            $ipAddress,
            $now,
        ): void {
            $credential = $this->enabledCredentialForUpdate(
                $session->actorUserId,
            );
            $this->verifyCredential($credential, $code, $now);
            $this->credentials->delete($session->actorUserId);
            $this->sessions->clearMfaVerificationForUser(
                $session->actorUserId,
            );
            $this->sessions->revokeOtherForUser(
                $session->actorUserId,
                $session->sessionId,
                'MFA_DISABLED',
            );
            $this->audit->record(
                eventType: 'MFA_DISABLED',
                outcome: 'SUCCESS',
                reasonCode: 'MFA_DISABLED',
                requestId: $requestId,
                actorUserId: $session->actorUserId,
                ipAddress: $ipAddress,
            );
        });
    }

    private function reauthenticate(
        SessionContext $session,
        #[SensitiveParameter]
        string $currentPassword,
    ): UserCredentials {
        $this->assertDirectSession($session);
        $user = $this->users->findById($session->actorUserId);

        if (
            $user === null
            || $user->status !== UserStatus::Active
            || !$this->passwordHasher->verify(
                $currentPassword,
                $user->passwordHash,
            )
        ) {
            throw new DomainProblemException(
                ProblemType::AuthenticationRequired,
                'MFA_REAUTHENTICATION_FAILED',
                'The current password could not be verified.',
            );
        }

        if ($this->passwordHasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash(
                $user->id,
                $this->passwordHasher->hash($currentPassword),
            );
        }

        return $user;
    }

    private function assertDirectSession(SessionContext $session): void
    {
        if ($session->impersonation !== null) {
            throw new DomainProblemException(
                ProblemType::PermissionDenied,
                'MFA_IMPERSONATION_FORBIDDEN',
                'Multi-factor authentication cannot be changed while impersonating.',
            );
        }
    }

    private function enabledCredentialForUpdate(string $userId): MfaCredential
    {
        $credential = $this->credentials->findForUpdate($userId);

        if ($credential === null || !$credential->isEnabled()) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'MFA_NOT_ENABLED',
                'Multi-factor authentication is not enabled.',
            );
        }

        return $credential;
    }

    /**
     * @return bool whether a recovery code was consumed
     */
    private function verifyCredential(
        MfaCredential $credential,
        #[SensitiveParameter]
        string $code,
        DateTimeImmutable $now,
    ): bool {
        $trimmed = trim($code);
        $secret = $this->decryptSecret($credential);
        $step = $this->totp->verify(
            $secret,
            $trimmed,
            $now,
            $credential->lastUsedStep,
        );

        if ($step !== null) {
            $this->credentials->updateVerificationState(
                $credential->userId,
                $step,
                $credential->recoveryCodeHashes,
            );

            return false;
        }

        $normalizedRecoveryCode = $this->normalizeRecoveryCode($trimmed);

        if ($normalizedRecoveryCode !== null) {
            $candidateHash = $this->hashRecoveryCode($normalizedRecoveryCode);
            $remainingHashes = [];
            $matched = false;

            foreach ($credential->recoveryCodeHashes as $storedHash) {
                if (!$matched && hash_equals($storedHash, $candidateHash)) {
                    $matched = true;

                    continue;
                }

                $remainingHashes[] = $storedHash;
            }

            if ($matched) {
                $this->credentials->updateVerificationState(
                    $credential->userId,
                    $credential->lastUsedStep,
                    $remainingHashes,
                );

                return true;
            }
        }

        throw new DomainProblemException(
            ProblemType::AuthenticationRequired,
            'MFA_CODE_INVALID',
            'The multi-factor authentication code is invalid.',
        );
    }

    private function decryptSecret(MfaCredential $credential): string
    {
        $payload = $this->cipher->decrypt(
            $credential->secretKeyId,
            $credential->encryptedSecret,
        );
        $secret = $payload['totp_secret'] ?? null;

        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException(
                'The encrypted MFA credential does not contain a TOTP secret.',
            );
        }

        return $secret;
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        $alphabetLength = strlen(self::RECOVERY_ALPHABET);

        for ($index = 0; $index < self::RECOVERY_CODE_COUNT; $index++) {
            $raw = '';

            for ($character = 0; $character < 16; $character++) {
                $raw .= self::RECOVERY_ALPHABET[
                    random_int(0, $alphabetLength - 1)
                ];
            }

            $codes[] = implode('-', str_split($raw, 4));
        }

        return $codes;
    }

    private function normalizeRecoveryCode(
        #[SensitiveParameter]
        string $code,
    ): ?string {
        $normalized = strtoupper(str_replace(['-', ' '], '', $code));

        return preg_match(
            '/^[' . self::RECOVERY_ALPHABET . ']{16}$/D',
            $normalized,
        ) === 1
            ? $normalized
            : null;
    }

    private function hashRecoveryCode(
        #[SensitiveParameter]
        string $code,
    ): string {
        return hash('sha256', str_replace('-', '', strtoupper($code)));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
