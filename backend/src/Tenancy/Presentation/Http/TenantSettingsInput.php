<?php

declare(strict_types=1);

namespace Sova\Tenancy\Presentation\Http;

use DateTimeZone;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Tenancy\Application\Settings\UpdateTenantGeneralInput;
use Sova\Tenancy\Application\Settings\UpdateTenantLocalizationInput;

final class TenantSettingsInput
{
    private const SUPPORTED_LOCALES = ['sk', 'en', 'cs', 'de', 'hu', 'pl'];

    /**
     * @param array<string, mixed> $payload
     */
    public function general(array $payload): UpdateTenantGeneralInput
    {
        $this->onlyFields($payload, ['name', 'expected_revision']);
        $nameValue = $payload['name'] ?? null;
        $name = is_string($nameValue) ? trim($nameValue) : '';

        if ($name === '' || mb_strlen($name) > 200) {
            throw $this->invalid(
                'name',
                'Enter a tenant name with at most 200 characters.',
            );
        }

        return new UpdateTenantGeneralInput(
            $name,
            $this->positiveInteger(
                $payload['expected_revision'] ?? null,
                'expected_revision',
            ),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function localization(
        array $payload,
    ): UpdateTenantLocalizationInput {
        $this->onlyFields($payload, [
            'default_locale',
            'timezone',
            'expected_revision',
        ]);
        $localeValue = $payload['default_locale'] ?? null;
        $locale = is_string($localeValue) ? trim($localeValue) : '';
        $timezoneValue = $payload['timezone'] ?? null;
        $timezone = is_string($timezoneValue) ? trim($timezoneValue) : '';

        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            throw $this->invalid(
                'default_locale',
                'Choose a supported locale.',
            );
        }

        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw $this->invalid(
                'timezone',
                'Choose an IANA time zone identifier.',
            );
        }

        return new UpdateTenantLocalizationInput(
            $locale,
            $timezone,
            $this->positiveInteger(
                $payload['expected_revision'] ?? null,
                'expected_revision',
            ),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $allowed
     */
    private function onlyFields(array $payload, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($payload), $allowed));

        if ($unknown !== []) {
            throw $this->invalid(
                'body',
                sprintf('Unsupported field: %s.', implode(', ', $unknown)),
            );
        }
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value) || $value < 1) {
            throw $this->invalid($field, 'Provide a positive current revision.');
        }

        return $value;
    }

    private function invalid(string $field, string $message): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'TENANT_SETTINGS_INPUT_INVALID',
            'The tenant settings input is invalid.',
            [$field => [$message]],
        );
    }
}
