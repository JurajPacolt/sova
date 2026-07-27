<?php

declare(strict_types=1);

namespace Sova\Workgroups\Application;

use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Workgroups\Domain\WorkgroupMemberRole;

final class WorkgroupMemberRoleValidator
{
    /**
     * @param array<mixed> $payload
     */
    public function validate(array $payload): WorkgroupMemberRole
    {
        $errors = $this->unknownFields($payload);
        $value = $payload['role'] ?? null;
        $role = is_string($value) ? WorkgroupMemberRole::tryFrom($value) : null;

        if ($role === null) {
            $errors['role'][] = sprintf(
                'Use one of: %s.',
                implode(', ', array_map(
                    static fn(WorkgroupMemberRole $candidate): string => $candidate->value,
                    WorkgroupMemberRole::cases(),
                )),
            );
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'WORKGROUP_MEMBER_ROLE_INVALID',
                'The workgroup member role input is invalid.',
                $errors,
            );
        }

        if (!$role instanceof WorkgroupMemberRole) {
            throw new \RuntimeException(
                'Validated workgroup member role is missing.',
            );
        }

        return $role;
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
            if ($field !== 'role') {
                $errors['body'][] = 'The request contains an unknown field.';
            }
        }

        return $errors;
    }
}
