<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\System;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use Sova\Authorization\Application\TenantRoleProvisioner;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;
use Sova\Tenancy\Application\Invitation\CreateInvitationService;
use Sova\Tenancy\Domain\Tenant\TenantStatus;

final readonly class SystemTenantAdministrationService
{
    private const DELETION_GRACE_PERIOD = '+30 days';

    public function __construct(
        private Connection $connection,
        private SystemTenantRepository $tenants,
        private TenantRoleProvisioner $roles,
        private CreateInvitationService $invitations,
        private SecurityAuditRecorder $audit,
    ) {}

    public function create(
        SystemTenantInput $input,
        string $idempotencyKey,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): SystemTenantCreationResult {
        $fingerprint = $this->fingerprint($input);
        $existing = $this->replay(
            $actorUserId,
            $idempotencyKey,
            $fingerprint,
            $input->ownerEmail,
        );

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->connection->transactional(function () use (
                $input,
                $idempotencyKey,
                $actorUserId,
                $requestId,
                $ipAddress,
                $fingerprint,
            ): SystemTenantCreationResult {
                $tenantId = (string) UuidV7::generate();
                $this->tenants->create(
                    $tenantId,
                    $input->name,
                    $input->slug,
                );
                $this->roles->provisionDefaults($tenantId, $actorUserId);
                $invitation = $this->invitations->create(
                    tenantId: $tenantId,
                    normalizedEmail: $input->ownerEmail,
                    actorUserId: $actorUserId,
                    requestId: $requestId,
                    ipAddress: $ipAddress,
                    initialRoleCode: DefaultRole::TenantOwner->value,
                );
                $this->tenants->rememberCreation(
                    $actorUserId,
                    $idempotencyKey,
                    $fingerprint,
                    $tenantId,
                );
                $this->audit->record(
                    eventType: 'SYSTEM_TENANT_CREATED',
                    outcome: 'SUCCESS',
                    reasonCode: 'TENANT_CREATED_PENDING_OWNER',
                    requestId: $requestId,
                    actorUserId: $actorUserId,
                    tenantId: $tenantId,
                    ipAddress: $ipAddress,
                    metadata: [
                        'owner_invitation_id' => $invitation->id,
                        'slug' => $input->slug,
                    ],
                );
                $tenant = $this->tenants->findById($tenantId);

                if ($tenant === null) {
                    throw new RuntimeException(
                        'The newly created tenant could not be loaded.',
                    );
                }

                return new SystemTenantCreationResult(
                    $tenant,
                    $input->ownerEmail,
                    false,
                );
            });
        } catch (UniqueConstraintViolationException) {
            $replayed = $this->replay(
                $actorUserId,
                $idempotencyKey,
                $fingerprint,
                $input->ownerEmail,
            );

            if ($replayed !== null) {
                return $replayed;
            }

            throw new DomainProblemException(
                ProblemType::Conflict,
                'TENANT_SLUG_TAKEN',
                'A tenant with this slug already exists.',
                ['slug' => ['Choose a different tenant slug.']],
            );
        }
    }

    /**
     * @return list<SystemTenantDetails>
     */
    public function list(): array
    {
        return $this->tenants->listAll();
    }

    public function changeStatus(
        string $tenantId,
        SystemTenantLifecycleInput $input,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): SystemTenantDetails {
        return $this->connection->transactional(function () use (
            $tenantId,
            $input,
            $actorUserId,
            $requestId,
            $ipAddress,
        ): SystemTenantDetails {
            $tenant = $this->tenants->findById($tenantId, true);

            if ($tenant === null || $tenant->status === TenantStatus::Deleted) {
                throw new DomainProblemException(
                    ProblemType::ResourceNotFound,
                    'SYSTEM_TENANT_NOT_FOUND',
                    'The tenant was not found.',
                );
            }

            if ($tenant->revision !== $input->revision) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_REVISION_CONFLICT',
                    'The tenant was changed by another operation. Reload and try again.',
                );
            }

            if ($tenant->status === $input->status) {
                return $tenant;
            }

            if (!$tenant->status->canTransitionTo($input->status)) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_STATUS_TRANSITION_INVALID',
                    'The requested tenant status transition is not allowed.',
                );
            }

            if (
                $input->status === TenantStatus::Active
                && $this->tenants->activeOwnerCount($tenantId) < 1
            ) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_ACTIVE_OWNER_REQUIRED',
                    'The tenant must have an active owner before activation.',
                );
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $deletionRequestedAt = $input->status === TenantStatus::DeletionPending
                ? $now
                : null;
            $deletionEffectiveAt = $deletionRequestedAt?->modify(
                self::DELETION_GRACE_PERIOD,
            );

            if (!$this->tenants->changeStatus(
                $tenantId,
                $input->revision,
                $input->status,
                $deletionRequestedAt,
                $deletionEffectiveAt,
            )) {
                throw new DomainProblemException(
                    ProblemType::Conflict,
                    'TENANT_REVISION_CONFLICT',
                    'The tenant was changed by another operation. Reload and try again.',
                );
            }

            $this->audit->record(
                eventType: 'SYSTEM_TENANT_STATUS_CHANGED',
                outcome: 'SUCCESS',
                reasonCode: 'TENANT_STATUS_CHANGED',
                requestId: $requestId,
                actorUserId: $actorUserId,
                tenantId: $tenantId,
                ipAddress: $ipAddress,
                metadata: [
                    'from_status' => $tenant->status->value,
                    'to_status' => $input->status->value,
                    'reason' => $input->reason,
                    'revision' => $input->revision + 1,
                ],
            );
            $updated = $this->tenants->findById($tenantId);

            if ($updated === null) {
                throw new RuntimeException(
                    'The updated tenant could not be loaded.',
                );
            }

            return $updated;
        });
    }

    private function fingerprint(SystemTenantInput $input): string
    {
        return hash(
            'sha256',
            implode("\n", [
                $input->name,
                $input->slug,
                $input->ownerEmail,
            ]),
        );
    }

    private function replay(
        string $actorUserId,
        string $idempotencyKey,
        string $fingerprint,
        string $ownerEmail,
    ): ?SystemTenantCreationResult {
        $record = $this->tenants->findCreationRecord(
            $actorUserId,
            $idempotencyKey,
        );

        if ($record === null) {
            return null;
        }

        if (!hash_equals($record->requestFingerprint, $fingerprint)) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'IDEMPOTENCY_KEY_REUSED',
                'The idempotency key was already used for a different request.',
            );
        }

        $tenant = $this->tenants->findById($record->tenantId);

        if ($tenant === null) {
            throw new RuntimeException(
                'The idempotent tenant creation result is missing.',
            );
        }

        return new SystemTenantCreationResult(
            $tenant,
            $ownerEmail,
            true,
        );
    }
}
