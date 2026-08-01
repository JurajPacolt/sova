<?php

declare(strict_types=1);

namespace Sova\Issues\Domain;

/** Fixed for the MVP; projects may gain their own priorities later. */
enum IssuePriority: string
{
    case Low = 'LOW';
    case Normal = 'NORMAL';
    case High = 'HIGH';
    case Critical = 'CRITICAL';
}
