<?php

declare(strict_types=1);

namespace Sova\Shared\Application\Audit;

use DateTimeImmutable;
use JsonException;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

final readonly class AuditCursor
{
    public function __construct(
        public DateTimeImmutable $occurredAt,
        public string $id,
    ) {}

    public function encode(): string
    {
        try {
            $json = json_encode(
                [
                    'occurred_at' => $this->occurredAt->format(
                        'Y-m-d\TH:i:s.uP',
                    ),
                    'id' => $this->id,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'AUDIT_QUERY_INVALID',
                'The audit cursor is invalid.',
                ['cursor' => ['Provide the cursor returned by the API.']],
                $exception,
            );
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(string $token): self
    {
        $padding = (4 - strlen($token) % 4) % 4;
        $json = base64_decode(
            strtr($token . str_repeat('=', $padding), '-_', '+/'),
            true,
        );

        if ($json === false) {
            throw self::invalid();
        }

        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw self::invalid($exception);
        }

        if (
            !is_array($payload)
            || !is_string($payload['occurred_at'] ?? null)
            || !is_string($payload['id'] ?? null)
            || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $payload['id'],
            ) !== 1
        ) {
            throw self::invalid();
        }

        $occurredAt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.uP',
            $payload['occurred_at'],
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $occurredAt === false
            || (
                is_array($errors)
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
            )
        ) {
            throw self::invalid();
        }

        return new self($occurredAt, strtolower($payload['id']));
    }

    private static function invalid(
        ?JsonException $previous = null,
    ): DomainProblemException {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'AUDIT_QUERY_INVALID',
            'The audit cursor is invalid.',
            ['cursor' => ['Provide the cursor returned by the API.']],
            $previous,
        );
    }
}
