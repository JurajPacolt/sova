<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Invitation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SensitiveParameter;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Identity\Application\Authentication\SessionContext;
use Sova\Identity\Application\Authentication\UserCredentials;
use Sova\Identity\Application\Authentication\UserCredentialsRepository;
use Sova\Identity\Application\Security\OneTimeTokenGenerator;
use Sova\Identity\Application\Security\PasswordHasher;
use Sova\Identity\Application\Security\PasswordPolicy;
use Sova\Identity\Domain\User\UserStatus;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Tenancy\Domain\Membership\MembershipStatus;

final readonly class InvitationAccessService
{
    public function __construct(
        private Connection $connection,
        private InvitationRepository $invitations,
        private UserCredentialsRepository $users,
        private OneTimeTokenGenerator $tokenGenerator,
        private PasswordPolicy $passwordPolicy,
        private PasswordHasher $passwordHasher,
        private SecurityAuditRecorder $audit,
    ) {}

    public function inspect(
        #[SensitiveParameter]
        string $plainTextToken,
    ): TenantInvitation {
        return $this->usableInvitation($plainTextToken);
    }

    public function acceptNewAccount(
        #[SensitiveParameter]
        string $plainTextToken,
        string $displayName,
        string $preferredLocale,
        #[SensitiveParameter]
        string $password,
        string $requestId,
        ?string $ipAddress,
    ): AcceptedInvitation {
        $preview = $this->usableInvitation($plainTextToken);
        $tokenHash = $this->tokenGenerator->hash($plainTextToken);
        $userId = (string) UuidV7::generate();
        $candidate = new UserCredentials(
            id: $userId,
            email: $preview->email,
            passwordHash: '',
            displayName: $displayName,
            preferredLocale: $preferredLocale,
            status: UserStatus::PendingVerification,
            emailVerifiedAt: null,
            isSuperadmin: false,
        );
        $this->passwordPolicy->assertAcceptable($password, $candidate);
        $passwordHash = $this->passwordHasher->hash($password);

        try {
            return $this->connection->transactional(function () use (
                $tokenHash,
                $userId,
                $displayName,
                $preferredLocale,
                $passwordHash,
                $requestId,
                $ipAddress,
            ): AcceptedInvitation {
                $invitation = $this->invitations->findUsableByTokenHash(
                    $tokenHash,
                    true,
                );

                if ($invitation === null) {
                    throw $this->invalidToken();
                }

                if ($this->users->findByNormalizedEmail(
                    $invitation->normalizedEmail,
                ) !== null) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'INVITATION_ACCOUNT_EXISTS',
                        'Sign in with the invited account to accept this invitation.',
                    );
                }

                $this->invitations->createVerifiedUser(
                    userId: $userId,
                    email: $invitation->email,
                    normalizedEmail: $invitation->normalizedEmail,
                    passwordHash: $passwordHash,
                    displayName: $displayName,
                    preferredLocale: $preferredLocale,
                );
                $membershipId = (string) UuidV7::generate();
                $this->invitations->createMembership(
                    membershipId: $membershipId,
                    tenantId: $invitation->tenantId,
                    userId: $userId,
                );
                $this->applyInitialRole(
                    $invitation,
                    $membershipId,
                );

                if (!$this->invitations->accept($invitation->id, $userId)) {
                    throw $this->invalidToken();
                }

                $this->recordAccepted(
                    $invitation,
                    $userId,
                    $requestId,
                    $ipAddress,
                    true,
                );

                return new AcceptedInvitation(
                    userId: $userId,
                    tenantId: $invitation->tenantId,
                    tenantSlug: $invitation->tenantSlug,
                    membershipCreated: true,
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'INVITATION_ACCOUNT_EXISTS',
                'Sign in with the invited account to accept this invitation.',
            );
        }
    }

    public function acceptExistingAccount(
        #[SensitiveParameter]
        string $plainTextToken,
        SessionContext $session,
        string $requestId,
        ?string $ipAddress,
    ): AcceptedInvitation {
        if (!$this->tokenGenerator->hasValidFormat($plainTextToken)) {
            throw $this->invalidToken();
        }

        $tokenHash = $this->tokenGenerator->hash($plainTextToken);

        return $this->connection->transactional(function () use (
            $tokenHash,
            $session,
            $requestId,
            $ipAddress,
        ): AcceptedInvitation {
            $invitation = $this->invitations->findUsableByTokenHash(
                $tokenHash,
                true,
            );

            if ($invitation === null) {
                throw $this->invalidToken();
            }

            if (!hash_equals(
                $invitation->normalizedEmail,
                strtolower(trim($session->email)),
            )) {
                throw new DomainProblemException(
                    ProblemType::PermissionDenied,
                    'INVITATION_ACCOUNT_MISMATCH',
                    'Sign in with the account that received this invitation.',
                );
            }

            $membershipStatus = $this->invitations->membershipStatus(
                $invitation->tenantId,
                $session->userId,
            );
            $membershipCreated = false;

            if ($membershipStatus === null) {
                $membershipId = (string) UuidV7::generate();
                $this->invitations->createMembership(
                    membershipId: $membershipId,
                    tenantId: $invitation->tenantId,
                    userId: $session->userId,
                );
                $membershipCreated = true;
            } elseif ($membershipStatus !== MembershipStatus::Active) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'INVITATION_MEMBERSHIP_BLOCKED',
                    'This invitation cannot reactivate a disabled or removed membership.',
                );
            } else {
                $membershipId = $this->invitations->membershipId(
                    $invitation->tenantId,
                    $session->userId,
                );

                if ($membershipId === null) {
                    throw new \RuntimeException(
                        'The active invited membership could not be loaded.',
                    );
                }
            }

            $this->applyInitialRole(
                $invitation,
                $membershipId,
            );

            if (!$this->invitations->accept(
                $invitation->id,
                $session->userId,
            )) {
                throw $this->invalidToken();
            }

            $this->recordAccepted(
                $invitation,
                $session->userId,
                $requestId,
                $ipAddress,
                $membershipCreated,
            );

            return new AcceptedInvitation(
                userId: $session->userId,
                tenantId: $invitation->tenantId,
                tenantSlug: $invitation->tenantSlug,
                membershipCreated: $membershipCreated,
            );
        });
    }

    private function usableInvitation(
        #[SensitiveParameter]
        string $plainTextToken,
    ): TenantInvitation {
        if (!$this->tokenGenerator->hasValidFormat($plainTextToken)) {
            throw $this->invalidToken();
        }

        $invitation = $this->invitations->findUsableByTokenHash(
            $this->tokenGenerator->hash($plainTextToken),
        );

        if ($invitation === null) {
            throw $this->invalidToken();
        }

        return $invitation;
    }

    private function recordAccepted(
        TenantInvitation $invitation,
        string $userId,
        string $requestId,
        ?string $ipAddress,
        bool $membershipCreated,
    ): void {
        $this->audit->record(
            eventType: 'TENANT_INVITATION_ACCEPTED',
            outcome: 'SUCCESS',
            reasonCode: 'INVITATION_ACCEPTED',
            requestId: $requestId,
            actorUserId: $userId,
            tenantId: $invitation->tenantId,
            ipAddress: $ipAddress,
            metadata: [
                'invitation_id' => $invitation->id,
                'membership_created' => $membershipCreated,
                'initial_role_code' => $invitation->initialRoleCode,
            ],
        );
    }

    private function applyInitialRole(
        TenantInvitation $invitation,
        string $membershipId,
    ): void {
        if ($invitation->initialRoleCode === null) {
            return;
        }

        if (!$this->invitations->assignInitialRole(
            $invitation->tenantId,
            $membershipId,
            $invitation->initialRoleCode,
            $invitation->invitedByUserId,
        )) {
            throw new \RuntimeException(
                'The invitation initial role could not be assigned.',
            );
        }

        if (
            $invitation->initialRoleCode
            === DefaultRole::TenantOwner->value
        ) {
            $this->invitations->activatePendingTenant(
                $invitation->tenantId,
            );
        }
    }

    private function invalidToken(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Gone,
            'INVITATION_TOKEN_INVALID',
            'The invitation is invalid or has expired.',
        );
    }
}
