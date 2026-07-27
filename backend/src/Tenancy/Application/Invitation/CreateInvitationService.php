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
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Shared\Infrastructure\Configuration\Settings;

final readonly class CreateInvitationService
{
    private int $invitationTtlSeconds;
    private int $deliveryRequestTtlSeconds;

    public function __construct(
        private Connection $connection,
        private InvitationRepository $invitations,
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
    }

    public function create(
        string $tenantId,
        string $normalizedEmail,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        ?string $initialRoleCode = null,
        ?string $effectiveUserId = null,
    ): CreatedTenantInvitation {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify(sprintf(
            '+%d seconds',
            $this->invitationTtlSeconds,
        ));
        $deliveryExpiresAt = $now->modify(sprintf(
            '+%d seconds',
            $this->deliveryRequestTtlSeconds,
        ));

        return $this->connection->transactional(function () use (
            $tenantId,
            $normalizedEmail,
            $actorUserId,
            $requestId,
            $ipAddress,
            $expiresAt,
            $deliveryExpiresAt,
            $initialRoleCode,
            $effectiveUserId,
        ): CreatedTenantInvitation {
            if (!$this->invitations->lockTenant($tenantId)) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'INVITATION_TENANT_UNAVAILABLE',
                    'Invitations cannot be created for this tenant state.',
                );
            }
            $this->invitations->expirePendingForEmail(
                $tenantId,
                $normalizedEmail,
            );

            if ($this->invitations->activeMembershipExistsForEmail(
                $tenantId,
                $normalizedEmail,
            )) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'INVITATION_ALREADY_MEMBER',
                    'The invited account is already an active tenant member.',
                );
            }

            if ($this->invitations->pendingExists(
                $tenantId,
                $normalizedEmail,
            )) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'INVITATION_ALREADY_PENDING',
                    'A valid invitation for this tenant and email already exists.',
                );
            }

            $invitationId = (string) UuidV7::generate();
            $token = $this->tokenGenerator->generate();
            $this->invitations->create(
                invitationId: $invitationId,
                tenantId: $tenantId,
                email: $normalizedEmail,
                normalizedEmail: $normalizedEmail,
                invitedByUserId: $actorUserId,
                tokenHash: $token->hash(),
                expiresAt: $expiresAt,
                initialRoleCode: $initialRoleCode,
            );
            $this->publisher->publish(
                invitationId: $invitationId,
                tenantId: $tenantId,
                plainTextToken: $token->plainText(),
                deliveryExpiresAt: $deliveryExpiresAt,
            );
            $this->audit->record(
                eventType: 'TENANT_INVITATION_CREATED',
                outcome: 'SUCCESS',
                reasonCode: 'INVITATION_CREATED',
                requestId: $requestId,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                effectiveUserId: $effectiveUserId,
                ipAddress: $ipAddress,
                metadata: [
                    'invitation_id' => $invitationId,
                    'initial_role_code' => $initialRoleCode,
                ],
            );

            return new CreatedTenantInvitation(
                id: $invitationId,
                tenantId: $tenantId,
                email: $normalizedEmail,
                expiresAt: $expiresAt,
            );
        });
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
}
