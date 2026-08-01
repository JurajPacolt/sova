<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\QueryLanguage;

/**
 * The database-independent front door of the query language: it enforces the
 * length limit, lexes and parses the text, runs static semantic validation and,
 * for a clean query, produces the canonical form. Reference existence and
 * access are resolved later, inside the caller's authorised scope.
 */
final readonly class SovaQlAnalyzer
{
    public function __construct(
        private FieldCatalog $fields,
        private FunctionCatalog $functions,
        private QueryLimits $limits,
    ) {}

    public function analyze(string $text): AnalyzedQuery
    {
        if (strlen($text) > $this->limits->maxQueryBytes) {
            return new AnalyzedQuery(false, null, [
                new QueryError(
                    QueryErrorCode::TooLong,
                    0,
                    mb_strlen($text),
                    ['limit' => $this->limits->maxQueryBytes],
                ),
            ], null);
        }

        try {
            $tokens = (new Lexer($text))->tokenize();
            $ast = (new Parser($tokens, $this->limits->maxParenDepth))->parse();
        } catch (SovaQlSyntaxException $exception) {
            return new AnalyzedQuery(false, null, [$exception->error()], null);
        }

        $validator = new SemanticValidator($this->fields, $this->functions, $this->limits);
        $errors = $validator->validate($ast);

        if ($errors !== []) {
            return new AnalyzedQuery(false, $ast, $errors, null);
        }

        $canonical = (new CanonicalPrinter($this->fields, $this->functions))->print($ast);

        return new AnalyzedQuery(true, $ast, [], $canonical);
    }
}
