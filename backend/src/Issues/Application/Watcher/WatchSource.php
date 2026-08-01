<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Watcher;

/**
 * Why a member is watching. It is stored so the automatic rules of webflow §6
 * can be shown to the user rather than being invisible magic.
 */
enum WatchSource: string
{
    case Explicit = 'EXPLICIT';
    case Author = 'AUTHOR';
    case Assignee = 'ASSIGNEE';
    case Comment = 'COMMENT';
}
