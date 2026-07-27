<?php

declare(strict_types=1);

namespace Sova\Identity\Application\Impersonation;

use InvalidArgumentException;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class ImpersonationInputValidator
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function validate(array $payload): ImpersonationInput
    {
        $tenantId = $this->uuid($payload['tenant_id'] ?? null);
        $effectiveUserId = $this->uuid(
            $payload['effective_user_id'] ?? null,
        );
        $reasonValue = $payload['reason'] ?? null;
        $reason = is_string($reasonValue) ? trim($reasonValue) : '';
        $passwordValue = $payload['password'] ?? null;
        $password = is_string($passwordValue) ? $passwordValue : '';
        $errors = [];

        if ($tenantId === null) {
            $errors['tenant_id'] = ['Provide a valid tenant UUID.'];
        }

        if ($effectiveUserId === null) {
            $errors['effective_user_id'] = [
                'Provide a valid effective user UUID.',
            ];
        }

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $errors['reason'] = [
                'Provide a reason between 10 and 500 characters.',
            ];
        }

        if ($password === '' || strlen($password) > 1024) {
            $errors['password'] = [
                'Enter the current administrator password.',
            ];
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'IMPERSONATION_INPUT_INVALID',
                'The impersonation input is invalid.',
                $errors,
            );
        }

        if ($tenantId === null || $effectiveUserId === null) {
            throw new \LogicException(
                'Validated impersonation identifiers must be available.',
            );
        }

        return new ImpersonationInput(
            $tenantId,
            $effectiveUserId,
            $reason,
            $password,
        );
    }

    private function uuid(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
