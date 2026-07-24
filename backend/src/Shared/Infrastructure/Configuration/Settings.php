<?php

declare(strict_types=1);

namespace Sova\Shared\Infrastructure\Configuration;

use RuntimeException;

final readonly class Settings
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function require(string $key): mixed
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Required setting "%s" is missing.', $key));
        }

        return $value;
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->get($key);

        if (is_string($value)) {
            return $value;
        }

        if ($default !== null) {
            return $default;
        }

        throw $this->invalidType($key, 'string');
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->get($key);

        if (is_int($value)) {
            return $value;
        }

        if ($default !== null) {
            return $default;
        }

        throw $this->invalidType($key, 'integer');
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->get($key);

        if (is_bool($value)) {
            return $value;
        }

        if ($default !== null) {
            return $default;
        }

        throw $this->invalidType($key, 'boolean');
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->get($key, []);

        if (!is_array($value)) {
            throw $this->invalidType($key, 'list of strings');
        }

        $result = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw $this->invalidType($key, 'list of strings');
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    private function invalidType(string $key, string $expected): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Setting "%s" must be a %s.',
            $key,
            $expected,
        ));
    }
}
