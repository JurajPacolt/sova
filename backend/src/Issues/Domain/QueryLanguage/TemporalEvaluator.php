<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Sova\Issues\Domain\QueryLanguage\Ast\FunctionCall;
use Sova\Issues\Domain\QueryLanguage\Ast\StringLiteral;

/**
 * Turns the relative time functions of spec §4.7 into a concrete instant. The
 * offset is applied first and the boundary is taken afterwards, so
 * `startOfDay("-7d")` means "the start of the day seven days ago", not "seven
 * days before the start of today" — the two differ across a DST change.
 *
 * Anchors are computed in the caller's zone and returned in UTC, because that is
 * how every timestamp is stored and compared. SOVA does not persist a per-user
 * time zone yet, so callers pass UTC; when that preference lands, only the zone
 * handed in here changes.
 */
final readonly class TemporalEvaluator
{
    private DateTimeImmutable $now;

    public function __construct(
        DateTimeZone $zone,
        ?DateTimeImmutable $now = null,
    ) {
        $this->now = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone);
    }

    /**
     * @throws InvalidArgumentException when the call is not a known time anchor;
     *                                  the semantic validator has already ruled
     *                                  that out for a validated AST
     */
    public function evaluate(FunctionCall $function): DateTimeImmutable
    {
        $anchor = $this->applyOffset($this->now, $this->offsetOf($function));

        return match (strtolower($function->name)) {
            'now' => $this->utc($anchor),
            'startofday' => $this->startOfDay($anchor),
            'endofday' => $this->endOfDay($anchor),
            'startofweek' => $this->startOfDay($this->monday($anchor)),
            'endofweek' => $this->endOfDay($this->monday($anchor)->modify('+6 days')),
            'startofmonth' => $this->startOfDay($anchor->modify('first day of this month')),
            'endofmonth' => $this->endOfDay($anchor->modify('last day of this month')),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown SovaQL time function "%s".',
                $function->name,
            )),
        };
    }

    private function offsetOf(FunctionCall $function): ?string
    {
        $argument = $function->arguments[0] ?? null;

        return $argument instanceof StringLiteral ? $argument->value : null;
    }

    private function applyOffset(
        DateTimeImmutable $moment,
        ?string $offset,
    ): DateTimeImmutable {
        if ($offset === null || preg_match('/^([+-]?)(\d+)([mhdwM])$/', $offset, $parts) !== 1) {
            return $moment;
        }

        $amount = (int) $parts[2];
        $interval = match ($parts[3]) {
            'm' => new DateInterval(sprintf('PT%dM', $amount)),
            'h' => new DateInterval(sprintf('PT%dH', $amount)),
            'd' => new DateInterval(sprintf('P%dD', $amount)),
            'w' => new DateInterval(sprintf('P%dD', $amount * 7)),
            default => new DateInterval(sprintf('P%dM', $amount)),
        };

        return $parts[1] === '-' ? $moment->sub($interval) : $moment->add($interval);
    }

    /**
     * ISO-8601 week: Monday is the first day, whatever the server locale says.
     */
    private function monday(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->modify(sprintf('-%d days', (int) $moment->format('N') - 1));
    }

    private function startOfDay(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $this->utc($moment->setTime(0, 0, 0, 0));
    }

    private function endOfDay(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $this->utc($moment->setTime(23, 59, 59, 999_999));
    }

    private function utc(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTimezone(new DateTimeZone('UTC'));
    }
}
