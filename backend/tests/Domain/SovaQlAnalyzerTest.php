<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sova\Issues\Domain\QueryLanguage\Ast\ComparisonPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\EmptyPredicate;
use Sova\Issues\Domain\QueryLanguage\Ast\LogicalExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\NotExpression;
use Sova\Issues\Domain\QueryLanguage\Ast\SetPredicate;
use Sova\Issues\Domain\QueryLanguage\BasicFormProjector;
use Sova\Issues\Domain\QueryLanguage\CanonicalPrinter;
use Sova\Issues\Domain\QueryLanguage\FieldCatalog;
use Sova\Issues\Domain\QueryLanguage\FunctionCatalog;
use Sova\Issues\Domain\QueryLanguage\LogicalOperator;
use Sova\Issues\Domain\QueryLanguage\QueryErrorCode;
use Sova\Issues\Domain\QueryLanguage\QueryLimits;
use Sova\Issues\Domain\QueryLanguage\SortDirection;
use Sova\Issues\Domain\QueryLanguage\SortNulls;
use Sova\Issues\Domain\QueryLanguage\SovaQlAnalyzer;

/**
 * Contract tests for the SovaQL v1 front door: lexer, parser, typed AST,
 * semantic validation and canonicalisation, as required by
 * `docs/SOVAQL-A-DASHBOARDY.md` §4 and §16.1.
 *
 * The analyzer is deliberately database-free, so reference resolution
 * (`QUERY_VALUE_NOT_AVAILABLE`, `QUERY_VALUE_AMBIGUOUS`) and execution
 * (`QUERY_TIMEOUT`, `QUERY_CURSOR_INVALID`) are not exercised here — those
 * codes belong to the compiler running inside the caller's authorised scope.
 */
final class SovaQlAnalyzerTest extends TestCase
{
    private SovaQlAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SovaQlAnalyzer(
            new FieldCatalog(),
            new FunctionCatalog(),
            new QueryLimits(),
        );
    }

    #[DataProvider('specificationExamples')]
    public function testSpecificationExamplesAreValidAndCanonical(string $query): void
    {
        $result = $this->analyzer->analyze($query);

        self::assertSame([], $result->errors);
        self::assertTrue($result->valid);
        self::assertNotNull($result->ast);
        self::assertSame($query, $result->canonical);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function specificationExamples(): iterable
    {
        yield 'filter and sort' => [
            'project = SOVA AND statusCategory != DONE AND assignee = currentUser()'
                . ' ORDER BY priority DESC, updated DESC',
        ];
        yield 'set predicates' => ['type IN (BUG, STORY) AND priority IN (HIGH, CRITICAL)'];
        yield 'fulltext and relative time' => [
            'text ~ "timeout pri prihlaseni" AND created >= startOfDay("-30d")',
        ];
        yield 'empty and group' => ['assignee IS EMPTY AND group = group("Backend")'];
        yield 'multi project' => [
            'project IN (SOVA, OPS) AND status IN (OPEN, IN_PROGRESS) ORDER BY key ASC',
        ];
        yield 'members of a workgroup' => ['assignee IN membersOf("Backend")'];
        yield 'negated group' => ['NOT (project = SOVA OR type = BUG)'];
        yield 'issue key' => ['key IN (SOVA-1, SOVA-2)'];
        yield 'parent' => ['parent = SOVA-7 AND hierarchyLevel = -1'];
        yield 'iso timestamp' => ['created >= "2026-02-28T23:59:59Z"'];
        yield 'sort with nulls placement' => [
            'statusCategory != DONE ORDER BY resolved ASC NULLS LAST, priority DESC',
        ];
    }

    /**
     * Keywords, field names and enum values are case-insensitive on input, and
     * the canonical form is the single spelling used for the cursor hash, saved
     * queries and audit (spec §4.2).
     */
    public function testCanonicalFormNormalisesCaseAndTheSummaryAlias(): void
    {
        $result = $this->analyzer->analyze(
            'summary ~ "reset" and project = sova or type = bug order by updated desc',
        );

        self::assertTrue($result->valid);
        self::assertSame(
            'title ~ "reset" AND project = SOVA OR type = BUG ORDER BY updated DESC',
            $result->canonical,
        );
    }

    /**
     * `a OR b AND c` binds as `a OR (b AND c)`, so the canonical text may drop
     * the parentheses; `(a OR b) AND c` must keep them or the meaning changes.
     */
    public function testPrecedenceIsPreservedAndRedundantParenthesesAreDropped(): void
    {
        $loose = $this->analyzer->analyze('project = SOVA OR type = BUG AND priority = HIGH');
        self::assertTrue($loose->valid);
        self::assertSame(
            'project = SOVA OR type = BUG AND priority = HIGH',
            $loose->canonical,
        );

        $root = $loose->ast?->filter;
        self::assertInstanceOf(LogicalExpression::class, $root);
        self::assertSame(LogicalOperator::Or, $root->operator);
        self::assertInstanceOf(ComparisonPredicate::class, $root->left);
        self::assertInstanceOf(LogicalExpression::class, $root->right);
        self::assertSame(LogicalOperator::And, $root->right->operator);

        $grouped = $this->analyzer->analyze('(project = SOVA OR type = BUG) AND priority = HIGH');
        self::assertTrue($grouped->valid);
        self::assertSame(
            '(project = SOVA OR type = BUG) AND priority = HIGH',
            $grouped->canonical,
        );

        $redundant = $this->analyzer->analyze('((project = SOVA) AND (type = BUG))');
        self::assertTrue($redundant->valid);
        self::assertSame('project = SOVA AND type = BUG', $redundant->canonical);
    }

    /**
     * Canonicalisation must be a fixed point: re-analysing the canonical text
     * yields exactly the same text. The cursor is bound to this hash, so any
     * drift would silently invalidate live pagination.
     */
    #[DataProvider('specificationExamples')]
    public function testCanonicalFormIsStableUnderReanalysis(string $query): void
    {
        $first = $this->analyzer->analyze($query);
        self::assertTrue($first->valid);
        self::assertNotNull($first->canonical);

        $second = $this->analyzer->analyze($first->canonical);

        self::assertTrue($second->valid);
        self::assertSame($first->canonical, $second->canonical);
    }

    public function testTypedAstCarriesPredicateShapes(): void
    {
        $result = $this->analyzer->analyze(
            'NOT assignee IS NOT EMPTY AND type IN (BUG, STORY) ORDER BY key DESC NULLS FIRST',
        );

        self::assertTrue($result->valid);
        $query = $result->ast;
        self::assertNotNull($query);

        $root = $query->filter;
        self::assertInstanceOf(LogicalExpression::class, $root);

        $negation = $root->left;
        self::assertInstanceOf(NotExpression::class, $negation);
        $empty = $negation->operand;
        self::assertInstanceOf(EmptyPredicate::class, $empty);
        self::assertTrue($empty->negated);
        self::assertSame('assignee', $empty->field->name);

        $set = $root->right;
        self::assertInstanceOf(SetPredicate::class, $set);
        self::assertFalse($set->negated);
        self::assertCount(2, $set->values);
        self::assertNull($set->function);

        self::assertCount(1, $query->sort);
        self::assertSame('key', $query->sort[0]->field->name);
        self::assertSame(SortDirection::Descending, $query->sort[0]->direction);
        self::assertSame(SortNulls::First, $query->sort[0]->nulls);
    }

    /**
     * A query with no condition is legal and means "everything I may see": the
     * compiler always intersects it with the tenant, project and `issue.view`
     * scope, so an absent filter never widens access.
     */
    public function testFilterIsOptional(): void
    {
        $sortOnly = $this->analyzer->analyze('ORDER BY updated DESC');
        self::assertTrue($sortOnly->valid);
        self::assertNull($sortOnly->ast?->filter);
        self::assertSame('ORDER BY updated DESC', $sortOnly->canonical);

        $blank = $this->analyzer->analyze('');
        self::assertTrue($blank->valid);
        self::assertNull($blank->ast?->filter);
        self::assertSame([], $blank->ast?->sort);
        self::assertSame('', $blank->canonical);
    }

    /**
     * @param array<string, string|int> $arguments
     */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueryReportsStableCodeAndRange(
        string $query,
        QueryErrorCode $code,
        int $start,
        int $end,
        array $arguments = [],
    ): void {
        $result = $this->analyzer->analyze($query);

        self::assertFalse($result->valid);
        self::assertNull($result->canonical);
        self::assertNotSame([], $result->errors);

        $error = $result->errors[0];
        self::assertSame($code, $error->code);
        self::assertSame($start, $error->start, 'error start offset');
        self::assertSame($end, $error->end, 'error end offset');

        foreach ($arguments as $key => $value) {
            self::assertSame($value, $error->arguments[$key] ?? null);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: QueryErrorCode, 2: int, 3: int, 4?: array<string, string|int>}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'unknown field' => [
            'nope = 1', QueryErrorCode::FieldUnknown, 0, 4, ['field' => 'nope'],
        ];
        yield 'field delivered in a later phase' => [
            'labels = X', QueryErrorCode::FieldNotSupported, 0, 6, ['field' => 'labels'],
        ];
        yield 'reserved custom field namespace' => [
            'cf.story_points = 5',
            QueryErrorCode::FieldNotSupported,
            0,
            15,
            ['field' => 'cf.story_points'],
        ];
        yield 'fulltext operator on an enum' => [
            'priority ~ "x"',
            QueryErrorCode::OperatorNotAllowed,
            9,
            10,
            ['field' => 'priority', 'operator' => '~'],
        ];
        yield 'equality on fulltext' => [
            'text = "x"',
            QueryErrorCode::OperatorNotAllowed,
            5,
            6,
            ['field' => 'text', 'operator' => '='],
        ];
        yield 'empty on a mandatory timestamp' => [
            'created IS EMPTY',
            QueryErrorCode::OperatorNotAllowed,
            8,
            16,
            ['field' => 'created', 'operator' => 'IS EMPTY'],
        ];
        yield 'set operator on fulltext' => [
            'text IN ("a", "b")',
            QueryErrorCode::OperatorNotAllowed,
            5,
            7,
            ['field' => 'text', 'operator' => 'IN'],
        ];
        yield 'unsortable field' => [
            'project = SOVA ORDER BY text DESC',
            QueryErrorCode::OperatorNotAllowed,
            24,
            28,
            ['field' => 'text', 'operator' => 'ORDER BY'],
        ];
        yield 'value outside the enum' => [
            'priority = NOPE', QueryErrorCode::ValueInvalid, 11, 15, ['field' => 'priority'],
        ];
        yield 'text where an integer belongs' => [
            'hierarchyLevel = "x"', QueryErrorCode::ValueInvalid, 17, 20, ['field' => 'hierarchyLevel'],
        ];
        yield 'bare identity instead of a user function' => [
            'assignee = SOVA', QueryErrorCode::ValueInvalid, 11, 15, ['field' => 'assignee'],
        ];
        yield 'user set against a workgroup field' => [
            'group IN membersOf("Backend")', QueryErrorCode::ValueInvalid, 9, 29, ['field' => 'group'],
        ];
        yield 'impossible calendar date' => [
            'created >= "2026-02-30"', QueryErrorCode::ValueInvalid, 11, 23, ['field' => 'created'],
        ];
        yield 'hour out of range' => [
            'created >= "2026-02-28T25:00"', QueryErrorCode::ValueInvalid, 11, 29, ['field' => 'created'],
        ];
        yield 'unknown function' => [
            'assignee = whoami()', QueryErrorCode::FunctionUnknown, 11, 19, ['function' => 'whoami'],
        ];
        yield 'malformed relative offset' => [
            'created >= startOfDay("7 days")',
            QueryErrorCode::FunctionArgumentInvalid,
            22,
            30,
            ['function' => 'startOfDay'],
        ];
        yield 'missing required argument' => [
            'assignee = user()', QueryErrorCode::FunctionArgumentInvalid, 11, 17, ['function' => 'user'],
        ];
        yield 'argument given to a niladic function' => [
            'assignee = currentUser("x")',
            QueryErrorCode::FunctionArgumentInvalid,
            11,
            27,
            ['function' => 'currentUser'],
        ];
        yield 'unknown symbol' => ['project @ SOVA', QueryErrorCode::SyntaxInvalid, 8, 9];
        yield 'missing value' => ['project =', QueryErrorCode::SyntaxInvalid, 9, 10];
        yield 'unterminated string' => ['project = "SOVA', QueryErrorCode::SyntaxInvalid, 10, 15];
        yield 'unbalanced closing parenthesis' => [
            'project = SOVA)', QueryErrorCode::SyntaxInvalid, 14, 15,
        ];
        yield 'dangling conjunction' => [
            'project = SOVA AND', QueryErrorCode::SyntaxInvalid, 18, 19,
        ];
        yield 'function call used as a field' => [
            'startOfDay("-7d") = 1', QueryErrorCode::SyntaxInvalid, 10, 11,
        ];
    }

    /**
     * Every problem is reported at once so the editor can highlight them all
     * instead of revealing one per round trip.
     */
    public function testAllSemanticErrorsAreCollected(): void
    {
        $result = $this->analyzer->analyze('nope = 1 AND (other = 2 OR priority = WRONG)');

        self::assertFalse($result->valid);
        self::assertCount(3, $result->errors);
        self::assertSame(
            [
                QueryErrorCode::FieldUnknown,
                QueryErrorCode::FieldUnknown,
                QueryErrorCode::ValueInvalid,
            ],
            array_map(
                static fn($error): QueryErrorCode => $error->code,
                $result->errors,
            ),
        );
    }

    /**
     * A malformed call reports its own precise cause once; the surrounding
     * value must not pile a generic "value invalid" on top of it.
     */
    public function testMalformedFunctionDoesNotAlsoReportAnInvalidValue(): void
    {
        $result = $this->analyzer->analyze('assignee = user()');

        self::assertCount(1, $result->errors);
        self::assertSame(QueryErrorCode::FunctionArgumentInvalid, $result->errors[0]->code);
    }

    public function testQueryLongerThanTheByteLimitIsRejectedBeforeParsing(): void
    {
        $result = $this->analyzer->analyze('title ~ "' . str_repeat('a', 8_200) . '"');

        self::assertFalse($result->valid);
        self::assertNull($result->ast);
        self::assertCount(1, $result->errors);
        self::assertSame(QueryErrorCode::TooLong, $result->errors[0]->code);
        self::assertSame(8_192, $result->errors[0]->arguments['limit'] ?? null);
    }

    /**
     * @param array<string, string|int> $arguments
     */
    #[DataProvider('complexityLimits')]
    public function testComplexityLimitsAreEnforced(string $query, array $arguments): void
    {
        $result = $this->analyzer->analyze($query);

        self::assertFalse($result->valid);
        self::assertSame(QueryErrorCode::TooComplex, $result->errors[0]->code);

        foreach ($arguments as $key => $value) {
            self::assertSame($value, $result->errors[0]->arguments[$key] ?? null);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, string|int>}>
     */
    public static function complexityLimits(): iterable
    {
        yield 'parenthesis depth' => [
            str_repeat('(', 11) . 'project = SOVA' . str_repeat(')', 11),
            ['limit' => 10],
        ];
        yield 'values in one IN' => [
            'project IN (' . implode(
                ', ',
                array_map(static fn(int $i): string => 'P' . $i, range(1, 101)),
            ) . ')',
            ['limit' => 100],
        ];
        yield 'ast nodes' => [
            implode(' AND ', array_fill(0, 60, 'project = SOVA')),
            ['limit' => 100],
        ];
        yield 'sort fields' => [
            'project = SOVA ORDER BY key, updated, created, resolved',
            ['limit' => 3],
        ];
    }

    public function testQueryAtTheComplexityBoundaryIsAccepted(): void
    {
        $atDepth = $this->analyzer->analyze(
            str_repeat('(', 10) . 'project = SOVA' . str_repeat(')', 10),
        );
        self::assertTrue($atDepth->valid);

        $atSortLimit = $this->analyzer->analyze(
            'project = SOVA ORDER BY key, updated, created',
        );
        self::assertTrue($atSortLimit->valid);
    }

    /**
     * Spec §4.12 lists 100 AST nodes and 100 values per `IN` as two independent
     * limits, but every value is itself an AST node, so a 100-value set costs
     * 101 nodes and the stricter node budget decides first. Deny-by-default
     * makes that the safe direction; the practical ceiling for one `IN` is 99
     * values, and raising it is an operator decision on `QueryLimits`, not a
     * silent change here.
     */
    public function testNodeBudgetDominatesAMaximalSetPredicate(): void
    {
        $values = static fn(int $count): string => 'project IN (' . implode(
            ', ',
            array_map(static fn(int $i): string => 'P' . $i, range(1, $count)),
        ) . ')';

        self::assertTrue($this->analyzer->analyze($values(99))->valid);

        $atSetLimit = $this->analyzer->analyze($values(100));
        self::assertFalse($atSetLimit->valid);
        self::assertSame(QueryErrorCode::TooComplex, $atSetLimit->errors[0]->code);
        self::assertSame(100, $atSetLimit->errors[0]->arguments['limit'] ?? null);
    }

    /**
     * Escapes survive the round trip, and a value colliding with a reserved
     * keyword stays quoted so re-parsing cannot reinterpret it as syntax.
     */
    public function testStringEscapingRoundTripsThroughTheCanonicalForm(): void
    {
        $result = $this->analyzer->analyze('text ~ "a \\"quoted\\" \\\\ path"');

        self::assertTrue($result->valid);
        self::assertSame('text ~ "a \\"quoted\\" \\\\ path"', $result->canonical);

        $reparsed = $this->analyzer->analyze((string) $result->canonical);
        self::assertTrue($reparsed->valid);
        self::assertSame($result->canonical, $reparsed->canonical);

        $keyword = $this->analyzer->analyze('title = "AND"');
        self::assertTrue($keyword->valid);
        self::assertSame('title = "AND"', $keyword->canonical);
    }

    /**
     * Offsets are UTF-8 codepoints, not bytes, so an editor highlighting a
     * problem after non-ASCII text points at the right character.
     */
    public function testErrorOffsetsAreCodepointsNotBytes(): void
    {
        $result = $this->analyzer->analyze('title ~ "úloha" AND nope = 1');

        self::assertFalse($result->valid);
        self::assertSame(QueryErrorCode::FieldUnknown, $result->errors[0]->code);
        self::assertSame(20, $result->errors[0]->start);
        self::assertSame(24, $result->errors[0]->end);
    }

    #[DataProvider('relativeOffsets')]
    public function testRelativeTimeOffsetUnits(string $offset, bool $expected): void
    {
        self::assertSame($expected, FunctionCatalog::isValidOffset($offset));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function relativeOffsets(): iterable
    {
        yield 'minutes' => ['-30m', true];
        yield 'hours' => ['+2h', true];
        yield 'days' => ['-7d', true];
        yield 'weeks' => ['-1w', true];
        yield 'calendar months' => ['-1M', true];
        yield 'unsigned' => ['7d', true];
        yield 'seconds are not a unit' => ['-30s', false];
        yield 'years are not a unit' => ['-1y', false];
        yield 'missing unit' => ['-7', false];
        yield 'spaced' => ['- 7d', false];
        yield 'empty' => ['', false];
    }
    /**
     * The control-based editor can only draw a conjunction of simple
     * conditions. Anything else is reported as not representable so the client
     * shows it read-only — silently dropping half of somebody's filter and then
     * running it would be far worse than refusing to draw it.
     *
     * @param list<string> $expected `field operator values` for each condition
     */
    #[DataProvider('basicFormQueries')]
    public function testBasicFormProjection(
        string $query,
        bool $representable,
        array $expected,
    ): void {
        $analyzed = $this->analyzer->analyze($query);
        self::assertTrue($analyzed->valid);
        self::assertNotNull($analyzed->ast);

        $fields = new FieldCatalog();
        $form = (new BasicFormProjector(
            $fields,
            new CanonicalPrinter($fields, new FunctionCatalog()),
        ))->project($analyzed->ast);

        self::assertSame($representable, $form->representable);
        self::assertSame(
            $expected,
            array_map(
                static fn($condition): string => trim(sprintf(
                    '%s %s %s',
                    $condition->field,
                    $condition->operator,
                    implode(', ', $condition->values),
                )),
                $form->conditions,
            ),
        );
    }

    /**
     * @return iterable<string, array{string, bool, list<string>}>
     */
    public static function basicFormQueries(): iterable
    {
        yield 'empty query' => ['', true, []];
        yield 'single condition' => ['project = SOVA', true, ['project = SOVA']];
        yield 'conjunction' => [
            'project = SOVA AND priority = HIGH',
            true,
            ['project = SOVA', 'priority = HIGH'],
        ];
        yield 'long conjunction stays flat' => [
            'project = SOVA AND type = BUG AND priority = HIGH',
            true,
            ['project = SOVA', 'type = BUG', 'priority = HIGH'],
        ];
        yield 'set predicate keeps its values' => [
            'type IN (BUG, STORY)',
            true,
            ['type IN BUG, STORY'],
        ];
        yield 'negated set' => ['type NOT IN (BUG)', true, ['type NOT IN BUG']];
        yield 'empty predicate carries no value' => [
            'assignee IS EMPTY',
            true,
            ['assignee IS EMPTY'],
        ];
        yield 'function value is printed canonically' => [
            'assignee = currentUser()',
            true,
            ['assignee = currentUser()'],
        ];
        yield 'sort only' => ['ORDER BY updated DESC', true, []];

        // Everything below has a meaning the basic editor cannot carry.
        yield 'disjunction' => ['project = SOVA OR type = BUG', false, []];
        yield 'negation' => ['NOT project = SOVA', false, []];
        yield 'grouped disjunction inside a conjunction' => [
            '(project = SOVA OR type = BUG) AND priority = HIGH',
            false,
            [],
        ];
    }

    public function testSortSurvivesEvenWhenTheFilterDoesNot(): void
    {
        $analyzed = $this->analyzer->analyze(
            'project = SOVA OR type = BUG ORDER BY priority DESC, updated ASC',
        );
        self::assertTrue($analyzed->valid);
        self::assertNotNull($analyzed->ast);

        $fields = new FieldCatalog();
        $form = (new BasicFormProjector(
            $fields,
            new CanonicalPrinter($fields, new FunctionCatalog()),
        ))->project($analyzed->ast);

        // Sorting is a flat list in both editors, so it always crosses over.
        self::assertFalse($form->representable);
        self::assertSame(
            ['priority DESC', 'updated ASC'],
            array_map(
                static fn($sort): string => $sort->field . ' ' . $sort->direction,
                $form->sort,
            ),
        );
    }
}
