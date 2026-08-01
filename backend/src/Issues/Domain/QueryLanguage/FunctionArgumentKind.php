<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/** What a function argument must look like lexically. */
enum FunctionArgumentKind
{
    /** An arbitrary non-empty text argument (a public UUID or unique name). */
    case Text;

    /** A relative time offset such as `-7d`, `+2h` or `-1w`. */
    case Offset;
}
