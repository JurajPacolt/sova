<?php

declare(strict_types=1);

namespace Sova\Tenancy\Application\Membership;

use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Tenancy\Domain\Membership\MembershipStatus;

final class TenantMembershipStatusValidator
{
    /**
     * @param array<mixed> $payload
     */
    public function validate(array $payload): MembershipStatus
    {
        $errors = $this->unknownFields($payload);

        $value = $payload['status'] ?? null;
        $status = is_string($value)
            ? MembershipStatus::tryFrom($value)
            : null;

        if ($status === null) {
            $errors['status'][] = sprintf(
                'Use one of: %s.',
                implode(', ', array_map(
                    static fn(
                        MembershipStatus $candidate,
                    ): string => $candidate->value,
                    MembershipStatus::cases(),
                )),
            );
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'TENANT_MEMBERSHIP_INPUT_INVALID',
                'The tenant membership input is invalid.',
                $errors,
            );
        }

        if (!$status instanceof MembershipStatus) {
            throw new \RuntimeException(
                'Validated tenant membership status is missing.',
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
