<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Domain;

/** Default is private; sharing is always an explicit act (spec §6.2). */
enum SavedQueryVisibility: string
{
    case Private_ = 'PRIVATE';
    case Shared = 'SHARED';
}
