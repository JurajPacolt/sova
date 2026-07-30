<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Settings;

use Doctrine\DBAL\Connection;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class TenantSettingsService
{
    public function __construct(
        private Connection $connection,
        private TenantSettingsRepository $settings,
        private SecurityAuditRecorder $audit,
    ) {}

    public function get(string $tenantId): TenantSettingsDetails
    {
        return $this->requireSettings($tenantId);
    }

    public function updateGeneral(
        string $tenantId,
        UpdateTenantGeneralInput $input,
        string $actorUserId,
        ?string $effectiveUserId,
        string $requestId,
        ?string $ipAddress,
    ): TenantSettingsDetails {
        return $this->connection->transactional(function () use (
            $tenantId,
            $input,
            $actorUserId,
            $effectiveUserId,
            $requestId,
            $ipAddress,
        ): TenantSettingsDetails {
            $current = $this->requireCurrentRevision(
                $tenantId,
                $input->expectedRevision,
            );

            if ($current->name === $input->name) {
                return $current;
            }

            if (!$this->settings->updateGeneral(
                $tenantId,
                $input->expectedRevision,
                $input->name,
            )) {
                throw $this->revisionConflict();
            }

            $this->record(
                'TENANT_GENERAL_SETTINGS_CHANGED',
                $tenantId,
                $actorUserId,
                $effectiveUserId,
                $requestId,
                $ipAddress,
            );

            return $this->requireSettings($tenantId);
        });
    }

    public function updateLocalization(
        string $tenantId,
        UpdateTenantLocalizationInput $input,
        string $actorUserId,
        ?string $effectiveUserId,
        string $requestId,
        ?string $ipAddress,
    ): TenantSettingsDetails {
        return $this->connection->transactional(function () use (
            $tenantId,
            $input,
            $actorUserId,
            $effectiveUserId,
            $requestId,
            $ipAddress,
        ): TenantSettingsDetails {
            $current = $this->requireCurrentRevision(
                $tenantId,
                $input->expectedRevision,
            );

            if (
                $current->defaultLocale === $input->defaultLocale
                && $current->timezone === $input->timezone
            ) {
                return $current;
            }

            if (!$this->settings->updateLocalization(
                $tenantId,
                $input->expectedRevision,
                $input->defaultLocale,
                $input->timezone,
            )) {
                throw $this->revisionConflict();
            }

            $this->record(
                'TENANT_LOCALIZATION_SETTINGS_CHANGED',
                $tenantId,
                $actorUserId,
                $effectiveUserId,
                $requestId,
                $ipAddress,
            );

            return $this->requireSettings($tenantId);
        });
    }

    private function requireCurrentRevision(
        string $tenantId,
        int $expectedRevision,
    ): TenantSettingsDetails {
        $current = $this->settings->find($tenantId, true);

        if ($current === null) {
            throw $this->notFound();
        }

        if ($current->revision !== $expectedRevision) {
            throw $this->revisionConflict();
        }

        return $current;
    }

    private function requireSettings(string $tenantId): TenantSettingsDetails
    {
        $settings = $this->settings->find($tenantId);

        if ($settings === null) {
            throw $this->notFound();
        }

        return $settings;
    }

    private function record(
        string $eventType,
        string $tenantId,
        string $actorUserId,
        ?string $effectiveUserId,
        string $requestId,
        ?string $ipAddress,
    ): void {
        $this->audit->record(
            eventType: $eventType,
            outcome: 'SUCCESS',
            reasonCode: 'TENANT_SETTINGS_CHANGED',
            requestId: $requestId,
            actorUserId: $actorUserId,
            tenantId: $tenantId,
            effectiveUserId: $effectiveUserId,
            ipAddress: $ipAddress,
        );
    }

    private function notFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'TENANT_SETTINGS_NOT_FOUND',
            'The tenant settings were not found.',
        );
    }

    private function revisionConflict(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Conflict,
            'TENANT_REVISION_CONFLICT',
            'The tenant was changed by another operation. Reload and try again.',
        );
    }
}
