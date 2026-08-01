<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/** A value usable on the right-hand side of a predicate or as a function argument. */
interface Value extends Node {}
