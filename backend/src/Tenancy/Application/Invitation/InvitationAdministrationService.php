<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Invitation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Sova\Identity\Application\Security\OneTimeTokenGenerator;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class InvitationAdministrationService
{
    private int $invitationTtlSeconds;
    private int $deliveryRequestTtlSeconds;
    private int $resendCooldownSeconds;

    public function __construct(
        private Connection $connection,
        private InvitationAdministrationRepository $invitations,
        private InvitationPublisher $publisher,
        private OneTimeTokenGenerator $tokenGenerator,
        private SecurityAuditRecorder $audit,
        Settings $settings,
    ) {
        $this->invitationTtlSeconds = $this->positiveSetting(
            $settings,
            'auth.invitation_ttl_seconds',
        );
        $this->deliveryRequestTtlSeconds = $this->positiveSetting(
            $settings,
            'auth.recovery_request_ttl_seconds',
        );
        $this->resendCooldownSeconds = $this->positiveSetting(
            $settings,
            'auth.invitation_resend_cooldown_seconds',
        );
    }

    /**
     * @return list<ManagedTenantInvitation>
     */
    public function list(string $tenantId): array
    {
        return $this->invitations->listForTenant($tenantId);
    }

    public function resend(
        string $tenantId,
        string $invitationId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        ?string $effectiveUserId = null,
    ): ManagedTenantInvitation {
        return $this->connection->transactional(function () use (
            $tenantId,
            $invitationId,
            $actorUserId,
            $requestId,
            $ipAddress,
            $effectiveUserId,
        ): ManagedTenantInvitation {
            $invitation = $this->pendingInvitation(
                $tenantId,
                $invitationId,
            );
            $retryAfter = $this->invitations->resendRetryAfter(
                $invitationId,
                $this->resendCooldownSeconds,
            );

            if ($retryAfter > 0) {
                throw new DomainProblemException(
                    ProblemType::RateLimitExceeded,
                    'INVITATION_RESEND_RATE_LIMITED',
                    'The invitation was sent recently. Wait before sending it again.',
                );
            }

            $token = $this->tokenGenerator->generate();

            if (!$this->invitations->replacePendingToken(
                $tenantId,
                $invitationId,
                $token->hash(),
            )) {
                throw $this->notPending();
            }

            // Invalidating queued payloads before publishing the rotated token
            // ensures that no older link can be delivered after a resend.
            $this->invitations->cancelPendingDeliveries($invitationId);
            $now = $this->now();
            $deliveryExpiresAt = $now->modify(sprintf(
                '+%d seconds',
                $this->deliveryRequestTtlSeconds,
            ));

            if ($deliveryExpiresAt > $invitation->expiresAt) {
                $deliveryExpiresAt = $invitation->expiresAt;
            }

            $this->publisher->publish(
                invitationId: $invitationId,
                tenantId: $tenantId,
                plainTextToken: $token->plainText(),
                deliveryExpiresAt: $deliveryExpiresAt,
            );
            $this->record(
                'TENANT_INVITATION_RESENT',
                'INVITATION_RESENT',
                $invitationId,
                $tenantId,
                $actorUserId,
                $requestId,
                $ipAddress,
                $effectiveUserId,
            );

            return $this->requiredInvitation($tenantId, $invitationId);
        });
    }

    public function changeExpiry(
        string $tenantId,
        string $invitationId,
        DateTimeImmutable $expiresAt,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        ?string $effectiveUserId = null,
    ): ManagedTenantInvitation {
        return $this->connection->transactional(function () use (
            $tenantId,
            $invitationId,
            $expiresAt,
            $actorUserId,
            $requestId,
            $ipAddress,
            $effectiveUserId,
        ): ManagedTenantInvitation {
            $this->pendingInvitation($tenantId, $invitationId);
            $now = $this->now();
            $maximum = $now->modify(sprintf(
                '+%d seconds',
                $this->invitationTtlSeconds,
            ));

            if ($expiresAt <= $now || $expiresAt > $maximum) {
                throw new DomainProblemException(
                    ProblemType::ValidationFailed,
                    'INVITATION_EXPIRY_INVALID',
                    'The invitation expiry must be in the future and within the configured invitation lifetime.',
                    [
                        'expires_at' => [
                            'Choose a future time within the configured invitation lifetime.',
                        ],
                    ],
                );
            }

            if (!$this->invitations->changePendingExpiry(
                $tenantId,
                $invitationId,
                $expiresAt,
            )) {
                throw $this->notPending();
            }

            $this->record(
                'TENANT_INVITATION_EXPIRY_CHANGED',
                'INVITATION_EXPIRY_CHANGED',
                $invitationId,
                $tenantId,
                $actorUserId,
                $requestId,
                $ipAddress,
                $effectiveUserId,
            );

            return $this->requiredInvitation($tenantId, $invitationId);
        });
    }

    public function revoke(
        string $tenantId,
        string $invitationId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        ?string $effectiveUserId = null,
    ): ManagedTenantInvitation {
        return $this->connection->transactional(function () use (
            $tenantId,
            $invitationId,
            $actorUserId,
            $requestId,
            $ipAddress,
            $effectiveUserId,
        ): ManagedTenantInvitation {
            $this->pendingInvitation($tenantId, $invitationId);

            if (!$this->invitations->revokePending(
                $tenantId,
                $invitationId,
            )) {
                throw $this->notPending();
            }

            $this->invitations->cancelPendingDeliveries($invitationId);
            $this->record(
                'TENANT_INVITATION_REVOKED',
                'INVITATION_REVOKED',
                $invitationId,
                $tenantId,
                $actorUserId,
                $requestId,
                $ipAddress,
                $effectiveUserId,
            );

            return $this->requiredInvitation($tenantId, $invitationId);
        });
    }

    private function pendingInvitation(
        string $tenantId,
        string $invitationId,
    ): ManagedTenantInvitation {
        $invitation = $this->invitations->findForTenant(
            $tenantId,
            $invitationId,
            true,
        );

        if ($invitation === null) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'INVITATION_NOT_FOUND',
                'The invitation does not exist in this tenant.',
            );
        }

        if ($invitation->status !== 'PENDING') {
            throw $this->notPending();
        }

        return $invitation;
    }

    private function requiredInvitation(
        string $tenantId,
        string $invitationId,
    ): ManagedTenantInvitation {
        $invitation = $this->invitations->findForTenant(
            $tenantId,
            $invitationId,
        );

        if ($invitation === null) {
            throw new \RuntimeException(
                'The invitation disappeared during its administration transaction.',
            );
        }

        return $invitation;
    }

    private function notPending(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Conflict,
            'INVITATION_NOT_PENDING',
            'Only a pending and unexpired invitation can be changed.',
        );
    }

    private function record(
        string $eventType,
        string $reasonCode,
        string $invitationId,
        string $tenantId,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        ?string $effectiveUserId,
    ): void {
        $this->audit->record(
            eventType: $eventType,
            outcome: 'SUCCESS',
            reasonCode: $reasonCode,
            requestId: $requestId,
            actorUserId: $actorUserId,
            tenantId: $tenantId,
            effectiveUserId: $effectiveUserId,
            ipAddress: $ipAddress,
            metadata: ['invitation_id' => $invitationId],
        );
    }

    private function positiveSetting(Settings $settings, string $key): int
    {
        $value = $settings->int($key);

        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Setting "%s" must be positive.',
                $key,
            ));
        }

        return $value;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
