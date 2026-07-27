<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\System;

use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Tenancy\Domain\Tenant\TenantStatus;
use ValueError;

final class SystemTenantLifecycleValidator
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function validate(array $payload): SystemTenantLifecycleInput
    {
        $statusValue = $payload['status'] ?? null;
        $revisionValue = $payload['revision'] ?? null;
        $reasonValue = $payload['reason'] ?? null;
        $reason = is_string($reasonValue) ? trim($reasonValue) : '';
        $errors = $this->unknownFields($payload);

        try {
            $status = TenantStatus::from(
                is_string($statusValue) ? $statusValue : '',
            );
        } catch (ValueError) {
            $status = null;
        }

        if ($status === null || $status === TenantStatus::Deleted) {
            $errors['status'] = ['Select a supported tenant lifecycle target.'];
        }

        if (!is_int($revisionValue) || $revisionValue < 1) {
            $errors['revision'] = ['Revision must be a positive integer.'];
        }

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $errors['reason'] = ['Reason must contain between 10 and 500 characters.'];
        }

        if ($errors !== [] || $status === null || !is_int($revisionValue)) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'SYSTEM_TENANT_LIFECYCLE_INPUT_INVALID',
                'The tenant lifecycle input is invalid.',
                $errors,
            );
        }

        return new SystemTenantLifecycleInput(
            $status,
            $revisionValue,
            $reason,
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<string, list<string>>
     */
    private function unknownFields(array $payload): array
    {
        foreach (array_keys($payload) as $field) {
            if (!in_array($field, ['status', 'revision', 'reason'], true)) {
                return ['body' => ['The request contains an unknown field.']];
            }
        }

        return [];
    }
}
