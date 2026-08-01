<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\TransitionRuleType;

/**
 * A single stored transition rule (condition, validator or action) with its
 * structured configuration.
 */
final readonly class RuleView
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(
        public string $id,
        public TransitionRuleType $ruleType,
        public string $ruleKey,
        public array $configuration,
        public int $position,
    ) {}
}
