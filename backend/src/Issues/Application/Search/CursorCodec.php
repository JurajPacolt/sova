<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use JsonException;
use RuntimeException;
use Sova\Shared\Infrastructure\Configuration\Settings;

/**
 * Signs and verifies page tokens.
 *
 * The signing key is derived from the existing sensitive-payload secret with a
 * domain-separating label, so the cursor cannot be forged and no new production
 * secret has to be provisioned. Verification is fail-closed: a token that does
 * not match the current binding is rejected outright rather than quietly
 * treated as "first page", because silently restarting pagination could hand
 * the caller rows a permission change was meant to remove.
 */
final readonly class CursorCodec
{
    private const string LABEL = 'sovaql-cursor-v1';

    private string $key;

    public function __construct(Settings $settings)
    {
        $secret = $settings->string('security.sensitive_payload_key', '');

        if (trim($secret) === '') {
            throw new RuntimeException(
                'SENSITIVE_PAYLOAD_KEY must be configured before search cursors can be signed.',
            );
        }

        $this->key = hash_hmac('sha256', self::LABEL, $secret, true);
    }

    public function encode(SearchCursor $cursor, CursorBinding $binding): string
    {
        try {
            $payload = json_encode(
                ['v' => $cursor->sortValues, 'i' => $cursor->issueId],
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('The search cursor could not be encoded.', 0, $exception);
        }

        $body = $this->base64UrlEncode($payload);

        return $body . '.' . $this->base64UrlEncode($this->signature($body, $binding));
    }

    public function decode(string $token, CursorBinding $binding): ?SearchCursor
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$body, $signature] = $parts;
        $expected = $this->signature($body, $binding);
        $provided = $this->base64UrlDecode($signature);

        if ($provided === null || !hash_equals($expected, $provided)) {
            return null;
        }

        $payload = $this->base64UrlDecode($body);

        if ($payload === null) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !is_array($decoded['v'] ?? null) || !is_string($decoded['i'] ?? null)) {
            return null;
        }

        $values = [];

        foreach ($decoded['v'] as $value) {
            if ($value !== null && !is_string($value)) {
                return null;
            }

            $values[] = $value;
        }

        return new SearchCursor($values, $decoded['i']);
    }

    private function signature(string $body, CursorBinding $binding): string
    {
        return hash_hmac('sha256', $body . '|' . $binding->fingerprint(), $this->key, true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
