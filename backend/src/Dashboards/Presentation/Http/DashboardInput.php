<?php

declare(strict_types=1);

namespace Sova\Dashboards\Presentation\Http;

use InvalidArgumentException;
use Sova\Dashboards\Domain\DashboardLayout;
use Sova\Dashboards\Domain\WidgetPlacement;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Reading the few scalars the dashboard routes accept.
 *
 * A malformed identifier answers `404` rather than `422`: a caller who guesses
 * at identifiers must not be able to tell "not a UUID" apart from "not yours".
 */
final readonly class DashboardInput
{
    public function identifier(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'DASHBOARD_NOT_FOUND',
                'The dashboard was not found.',
            );
        }
    }

    public function version(mixed $value): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        throw $this->invalid(
            'expected_version',
            'Send the version the edit was made against.',
        );
    }

    public function name(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'DASHBOARD_NAME_INVALID',
                'Give the dashboard a name.',
                ['name' => ['Give the dashboard a name.']],
            );
        }

        return mb_substr($value, 0, 160);
    }

    public function widgetIdentifier(string $value): string
    {
        try {
            return (string) UuidV7::fromString($value);
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'WIDGET_NOT_FOUND',
                'The widget was not found.',
            );
        }
    }

    /**
     * A malformed source identifier answers the same way an unreachable one
     * does, so a widget cannot be used to probe which saved queries exist.
     */
    public function sourceIdentifier(mixed $value): string
    {
        try {
            return (string) UuidV7::fromString(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw new DomainProblemException(
                ProblemType::ResourceNotFound,
                'WIDGET_DATA_SOURCE_NOT_FOUND',
                'The saved query is not available as a widget source.',
            );
        }
    }

    public function title(mixed $value): string
    {
        // Plain text, length-capped. The client renders it as text; there is no
        // markup path for it to travel down.
        return is_string($value) ? mb_substr(trim($value), 0, 160) : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'WIDGET_CONFIGURATION_INVALID',
                'The widget configuration does not match its type.',
                ['configuration' => ['The configuration must be an object.']],
            );
        }

        $configuration = [];

        foreach ($value as $key => $entry) {
            $configuration[(string) $key] = $entry;
        }

        return $configuration;
    }

    /**
     * @return list<WidgetPlacement>
     */
    public function placements(mixed $value): array
    {
        if (!is_array($value)) {
            throw $this->layoutInvalid();
        }

        $placements = [];

        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw $this->layoutInvalid();
            }

            $widgetId = $entry['id'] ?? null;

            if (!is_string($widgetId)) {
                throw $this->layoutInvalid();
            }

            $placements[] = new WidgetPlacement(
                $this->widgetIdentifier($widgetId),
                $this->coordinate($entry['x'] ?? null),
                $this->coordinate($entry['y'] ?? null),
                $this->coordinate($entry['width'] ?? null),
                $this->coordinate($entry['height'] ?? null),
            );

            if (count($placements) > DashboardLayout::MAX_WIDGETS) {
                throw $this->layoutInvalid();
            }
        }

        return $placements;
    }

    private function coordinate(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw $this->layoutInvalid();
    }

    private function layoutInvalid(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'DASHBOARD_LAYOUT_INVALID',
            'The arrangement is not valid.',
            ['widgets' => ['Every widget needs an id and non-negative coordinates.']],
        );
    }

    public function position(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw $this->invalid('position', 'The position must be a non-negative integer.');
    }

    private function invalid(string $field, string $message): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'DASHBOARD_INVALID',
            $message,
            [$field => [$message]],
        );
    }
}
