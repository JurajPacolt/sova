<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

enum FunctionReturnType
{
    case User;
    case UserSet;
    case Workgroup;
    case DateTime;
}
