<?php

declare(strict_types=1);

namespace Sova\Dashboards\Domain\Template;

/**
 * One saved query the starter template asks for.
 *
 * The `key` never reaches storage: it is how a widget in the same manifest
 * names its data source before the query exists and has an identifier. The
 * `name` is only a *proposal* — the owner may already have a query of that
 * name, and provisioning must not fail because of it.
 */
final readonly class TemplateQuery
{
    /**
     * @param list<string> $defaultColumns
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $query,
        public array $defaultColumns,
    ) {}
}
