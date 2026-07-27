<?php

declare(strict_types=1);

namespace Sova\Workgroups\Application;

final readonly class CreateWorkgroupInput
{
    public function __construct(
        public string $name,
        public string $description,
    ) {}
}
