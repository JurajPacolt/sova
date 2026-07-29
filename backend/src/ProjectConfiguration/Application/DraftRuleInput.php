<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Sova\ProjectConfiguration\Domain\TransitionRuleType;

/**
 * One rule of a draft transition as submitted by the editor. The type and key
 * are validated against {@see \Sova\ProjectConfiguration\Domain\TransitionRuleCatalog}.
 */
final readonly class DraftRuleInput
{
    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(
        public TransitionRuleType $ruleType,
        public string $ruleKey,
        public array $configuration,
        public int $position,
    ) {}
}
