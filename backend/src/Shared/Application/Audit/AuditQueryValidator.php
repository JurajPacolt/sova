<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Audit;

use DateTimeImmutable;
use Exception;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final class AuditQueryValidator
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 100;

    /**
     * @param array<array-key, mixed> $query
     */
    public function validate(array $query): AuditQuery
    {
        $errors = [];
        $limit = $this->limit($query['limit'] ?? null, $errors);
        $cursor = $this->cursor($query['cursor'] ?? null, $errors);
        $from = $this->dateTime('from', $query['from'] ?? null, $errors);
        $to = $this->dateTime('to', $query['to'] ?? null, $errors);
        $actorUserId = $this->pattern(
            'actor_user_id',
            $query['actor_user_id'] ?? null,
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            'Provide a valid UUID.',
            $errors,
        );
        $eventType = $this->pattern(
            'event_type',
            $query['event_type'] ?? null,
            '/^[A-Z][A-Z0-9_]{0,63}$/',
            'Use an uppercase audit event code.',
            $errors,
        );
        $outcome = $this->outcome($query['outcome'] ?? null, $errors);
        $requestId = $this->pattern(
            'request_id',
            $query['request_id'] ?? null,
            '/^[A-Za-z0-9._-]{8,128}$/',
            'Provide a valid request identifier.',
            $errors,
        );

        if ($from !== null && $to !== null && $from > $to) {
            $errors['to'][] = 'The end time must not precede the start time.';
        }

        if ($errors !== []) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'AUDIT_QUERY_INVALID',
                'The audit query is invalid.',
                $errors,
            );
        }

        return new AuditQuery(
            $limit,
            $cursor,
            $from,
            $to,
            $actorUserId,
            $eventType,
            $outcome,
            $requestId,
        );
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function limit(mixed $value, array &$errors): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_LIMIT;
        }

        if (
            !is_string($value)
            || preg_match('/^[0-9]{1,3}$/', $value) !== 1
            || (int) $value < 1
            || (int) $value > self::MAX_LIMIT
        ) {
            $errors['limit'][] = sprintf(
                'Choose a limit between 1 and %d.',
                self::MAX_LIMIT,
            );

            return self::DEFAULT_LIMIT;
        }

        return (int) $value;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function cursor(mixed $value, array &$errors): ?AuditCursor
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || strlen($value) > 512) {
            $errors['cursor'][] = 'Provide the cursor returned by the API.';

            return null;
        }

        try {
            return AuditCursor::decode($value);
        } catch (DomainProblemException) {
            $errors['cursor'][] = 'Provide the cursor returned by the API.';

            return null;
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function dateTime(
        string $field,
        mixed $value,
        array &$errors,
    ): ?DateTimeImmutable {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || strlen($value) > 64) {
            $errors[$field][] = 'Provide an RFC 3339 timestamp.';

            return null;
        }

        if (
            preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}'
                    . '(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/',
                $value,
            ) !== 1
        ) {
            $errors[$field][] = 'Provide an RFC 3339 timestamp.';

            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            $errors[$field][] = 'Provide an RFC 3339 timestamp.';

            return null;
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function outcome(mixed $value, array &$errors): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (
            !is_string($value)
            || !in_array($value, ['SUCCESS', 'FAILURE'], true)
        ) {
            $errors['outcome'][] = 'Choose SUCCESS or FAILURE.';

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function pattern(
        string $field,
        mixed $value,
        string $pattern,
        string $message,
        array &$errors,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            $errors[$field][] = $message;

            return null;
        }

        return $value;
    }
}
