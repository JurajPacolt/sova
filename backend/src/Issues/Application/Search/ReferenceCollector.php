<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Search;

use Sova\Issues\Domain\QueryLanguage\Ast\ComparisonPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\EmptyPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\Expression;
use Sova\Issues\Domain\QueryLanguage\Ast\FunctionCall;
use Sova\Issues\Domain\QueryLanguage\Ast\IdentifierValue;
use Sova\Issues\Domain\QueryLanguage\Ast\LogicalExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\NotExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\Query;
use Sova\Issues\Domain\QueryLanguage\Ast\SetPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\StringLiteral;
use Sova\Issues\Domain\QueryLanguage\Ast\Value;
use Sova\Issues\Domain\QueryLanguage\FieldCatalog;
use Sova\Issues\Domain\QueryLanguage\FieldType;

/**
 * Walks a validated AST once and buckets every distinct external reference by
 * the kind of thing it names. Only the literal text is kept — the compiler owns
 * the value nodes and therefore reports the offending range itself.
 */
final class ReferenceCollector
{
    /** @var array<string, array<string, true>> */
    private array $buckets = [
        'project' => [],
        'type' => [],
        'status' => [],
        'key' => [],
        'member' => [],
        'workgroup' => [],
        'memberSet' => [],
    ];

    private bool $needsCurrentMember = false;

    public function __construct(private readonly FieldCatalog $fields) {}

    public function walk(Query $query): void
    {
        if ($query->filter !== null) {
            $this->expression($query->filter);
        }
    }

    public function request(): ReferenceRequest
    {
        return new ReferenceRequest(
            array_keys($this->buckets['project']),
            array_keys($this->buckets['type']),
            array_keys($this->buckets['status']),
            array_keys($this->buckets['key']),
            array_keys($this->buckets['member']),
            array_keys($this->buckets['workgroup']),
            array_keys($this->buckets['memberSet']),
            $this->needsCurrentMember,
        );
    }

    private function expression(Expression $expression): void
    {
        if ($expression instanceof LogicalExpression) {
            $this->expression($expression->left);
            $this->expression($expression->right);

            return;
        }

        if ($expression instanceof NotExpression) {
            $this->expression($expression->operand);

            return;
        }

        if ($expression instanceof ComparisonPredicate) {
            $this->value($expression->field->name, $expression->value);

            return;
        }

        if ($expression instanceof SetPredicate) {
            if ($expression->function !== null) {
                $this->value($expression->field->name, $expression->function);
            }

            foreach ($expression->values as $value) {
                $this->value($expression->field->name, $value);
            }

            return;
        }

        if ($expression instanceof EmptyPredicate) {
            return;
        }
    }

    private function value(string $fieldName, Value $value): void
    {
        if ($value instanceof FunctionCall) {
            $this->functionCall($value);

            return;
        }

        $literal = match (true) {
            $value instanceof IdentifierValue => $value->name,
            $value instanceof StringLiteral => $value->value,
            default => null,
        };

        if ($literal === null) {
            return;
        }

        $type = $this->fields->definition($fieldName)?->type;

        $bucket = match ($type) {
            FieldType::ProjectCode => 'project',
            FieldType::IssueTypeCode => 'type',
            FieldType::StatusCode => 'status',
            FieldType::IssueKey => 'key',
            default => null,
        };

        if ($bucket !== null) {
            $this->buckets[$bucket][strtoupper($literal)] = true;
        }
    }

    private function functionCall(FunctionCall $function): void
    {
        $name = strtolower($function->name);

        if ($name === 'currentuser') {
            $this->needsCurrentMember = true;

            return;
        }

        $argument = $function->arguments[0] ?? null;

        if (!$argument instanceof StringLiteral) {
            return;
        }

        $bucket = match ($name) {
            'user' => 'member',
            'group' => 'workgroup',
            'membersof' => 'memberSet',
            default => null,
        };

        if ($bucket !== null) {
            $this->buckets[$bucket][$argument->value] = true;
        }
    }
}
