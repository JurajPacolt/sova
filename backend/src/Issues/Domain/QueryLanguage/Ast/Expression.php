<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage\Ast;

/** Boolean expression tree node (logical connective or a leaf predicate). */
interface Expression extends Node {}
