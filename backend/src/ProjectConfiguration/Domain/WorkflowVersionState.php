<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Domain;

enum WorkflowVersionState: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case Retired = 'RETIRED';
}
