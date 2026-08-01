<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Domain;

enum ConfigurationStatus: string
{
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
}
