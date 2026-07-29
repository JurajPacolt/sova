<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Domain;

/**
 * System categories. A project renames and reorders its statuses but never
 * changes what a category means, because reports and boards depend on it.
 */
enum StatusCategory: string
{
    case ToDo = 'TO_DO';
    case InProgress = 'IN_PROGRESS';
    case Done = 'DONE';
}
