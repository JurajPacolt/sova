<?php

declare(strict_types=1);

namespace Sova\Identity\Application\System;

use Sova\Identity\Domain\User\UserStatus;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final class SystemUserStatusValidator
{
    /**
     * @var list<UserStatus>
     */
    private const ADMIN_TARGETS = [UserStatus::Active, UserStatus::Disabled];

    /**
     * @param array<mixed> $payload
     */
    public function validate(array $payload): UserStatus
    {
        $errors = $this->unknownFields($payload);
        $value = $payload['status'] ?? null;
        $status = is_string($value) ? UserStatus::tryFrom($value) : null;

        if ($status === null || !in_array($status, self::ADMIN_TARGETS, true)) {
            $errors['status'][] = sprintf(
                'Use one of: %s.',
                implode(', ', array_map(
                    static fn(UserStatus $candidate): string => $candidate->value,
                    self::ADMIN_TARGETS,
                )),
            );
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'SYSTEM_USER_STATUS_INVALID',
                'The system user status input is invalid.',
                $errors,
            );
        }

        if (!$status instanceof UserStatus) {
            throw new \RuntimeException(
                'Validated system user status is missing.',
            );
        }

        return $status;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, list<string>>
     */
    private function unknownFields(array $payload): array
    {
        $errors = [];

        foreach (array_keys($payload) as $field) {
            if ($field !== 'status') {
                $errors['body'][] = 'The request contains an unknown field.';
            }
        }

        return $errors;
    }
}
