<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Keeps secrets and personal data out of ordinary logs.
 *
 * The rule it implements is in `PROJECT_MEMORY.md`: secrets, passwords, session
 * tokens, personal data and sensitive content must not reach the repository, a
 * URL, ordinary logs, analytics or an error response.
 *
 * It redacts by **key**, not by guessing at values. A value-sniffing redactor
 * either misses the secret that does not look like one, or eats the identifier
 * that does — and a log where useful fields disappear at random is a log people
 * stop reading. The two value patterns it does apply (`Bearer …` and a
 * `token=…` query fragment) are the two ways a secret realistically ends up
 * inside a string that was logged for another reason.
 *
 * E-mail addresses are **masked, not removed**: `a***@example.test` still tells
 * an operator which domain and roughly which account a failing sign-in belongs
 * to, which is most of why the address was logged, without writing the address
 * itself into a file that outlives the incident.
 *
 * What it cannot reach: the message of an exception thrown by somebody else's
 * library. Monolog normalises a `Throwable` in the formatter, after every
 * processor has run. Code that raises an exception must therefore keep values
 * out of its message — SOVA's own domain exceptions carry static sentences —
 * and that limit is written down in `docs/OPERATIONS.md` rather than pretended
 * away here.
 */
final readonly class SensitiveDataProcessor implements ProcessorInterface
{
    public const string PLACEHOLDER = '[redacted]';

    /**
     * How deep to walk. A log context is a diagnostic payload, not a document;
     * anything nested deeper than this is already unreadable, and refusing to
     * recurse for ever is cheaper than trusting that nobody ever logs a cycle.
     */
    private const int MAX_DEPTH = 8;

    /**
     * Key fragments whose value is a secret whatever it happens to contain.
     *
     * Every entry names cryptographic or credential material. A bare `key` is
     * deliberately absent: `storage_key` and `cache_key` are diagnostics worth
     * reading, and a redactor that swallows them teaches people to distrust it.
     */
    private const array SENSITIVE_KEYS = [
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'credential',
        'csrf',
        'hmac',
        'passphrase',
        'password',
        'payload_key',
        'private_key',
        'secret',
        'signature',
        'signing_key',
        'token',
    ];

    /** Keys whose value is a person, so it is masked rather than dropped. */
    private const array EMAIL_KEYS = [
        'email',
        'normalized_email',
        'owner_email',
        'recipient',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->clean($record->context, 0),
            extra: $this->clean($record->extra, 0),
        );
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function clean(array $values, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [self::PLACEHOLDER];
        }

        $cleaned = [];

        foreach ($values as $key => $value) {
            $cleaned[$key] = $this->cleanValue((string) $key, $value, $depth);
        }

        return $cleaned;
    }

    private function cleanValue(string $key, mixed $value, int $depth): mixed
    {
        if ($this->isSensitive($key)) {
            // Even a null stays a placeholder: reporting "the password was
            // absent" is still reporting something about the password.
            return self::PLACEHOLDER;
        }

        if (is_array($value)) {
            return $this->clean($value, $depth + 1);
        }

        if (!is_string($value)) {
            return $value;
        }

        if ($this->isEmailKey($key)) {
            return $this->maskEmail($value);
        }

        return $this->maskSecretsIn($value);
    }

    private function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function isEmailKey(string $key): bool
    {
        return in_array(strtolower($key), self::EMAIL_KEYS, true);
    }

    private function maskEmail(string $value): string
    {
        $at = strrpos($value, '@');

        if ($at === false || $at === 0) {
            return self::PLACEHOLDER;
        }

        return substr($value, 0, 1) . '***' . substr($value, $at);
    }

    private function maskSecretsIn(string $value): string
    {
        $masked = preg_replace(
            '/\bBearer\s+\S+/i',
            'Bearer ' . self::PLACEHOLDER,
            $value,
        ) ?? $value;

        return preg_replace(
            '/([?&](?:token|code|state)=)[^&\s]+/i',
            '$1' . self::PLACEHOLDER,
            $masked,
        ) ?? $masked;
    }
}
