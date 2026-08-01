<?php

declare(strict_types=1);

namespace Sova\Shared\Domain\Error;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DomainProblemException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $fieldErrors
     */
    public function __construct(
        private readonly ProblemType $problemType,
        private readonly string $problemCode,
        string $safeDetail,
        private readonly array $fieldErrors = [],
        ?Throwable $previous = null,
    ) {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $problemCode) !== 1) {
            throw new InvalidArgumentException(
                'A domain problem code must use upper snake case.',
            );
        }

        if (trim($safeDetail) === '') {
            throw new InvalidArgumentException(
                'A domain problem must provide a non-empty safe detail.',
            );
        }

        if (
            $fieldErrors !== []
            && $problemType !== ProblemType::ValidationFailed
        ) {
            throw new InvalidArgumentException(
                'Field errors are supported only for validation problems.',
            );
        }

        foreach ($fieldErrors as $field => $messages) {
            if (trim($field) === '' || $messages === []) {
                throw new InvalidArgumentException(
                    'Every field error must have a field name and at least one message.',
                );
            }

            foreach ($messages as $message) {
                if (trim($message) === '') {
                    throw new InvalidArgumentException(
                        'Field error messages must not be empty.',
                    );
                }
            }
        }

        parent::__construct($safeDetail, previous: $previous);
    }

    public function problemType(): ProblemType
    {
        return $this->problemType;
    }

    public function problemCode(): string
    {
        return $this->problemCode;
    }

    /**
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
