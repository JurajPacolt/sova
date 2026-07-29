<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\Comment;

/**
 * Guards what may be stored as a comment.
 *
 * Comments are CommonMark **source**; the backend never renders them. Raw HTML
 * is refused at the boundary rather than left for the renderer to sanitise,
 * because a stored tag is one misconfigured renderer away from executing. The
 * check deliberately ignores fenced blocks and inline code spans: pasting
 * `<div>` into a code block is a normal thing to do in an issue tracker, and it
 * cannot become markup. Autolinks such as `<https://example.test>` are valid
 * CommonMark and stay allowed.
 *
 * A code span that spans several lines is not tracked, so HTML inside one is
 * rejected rather than accepted — the conservative direction.
 */
final class CommentBodyValidator
{
    public const int MAX_LENGTH = 20_000;

    public const string EMPTY_BODY = 'COMMENT_BODY_EMPTY';

    public const string TOO_LONG = 'COMMENT_BODY_TOO_LONG';

    public const string RAW_HTML = 'COMMENT_BODY_RAW_HTML';

    /**
     * @return list<string> stable violation codes, empty when the body is fine
     */
    public function violations(string $body): array
    {
        $violations = [];

        if (trim($body) === '') {
            $violations[] = self::EMPTY_BODY;
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            $violations[] = self::TOO_LONG;
        }

        if ($this->containsRawHtml($body)) {
            $violations[] = self::RAW_HTML;
        }

        return $violations;
    }

    private function containsRawHtml(string $body): bool
    {
        $fence = null;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $marker = $this->fenceMarker($line);

            if ($fence !== null) {
                // Only a fence of the same character and at least the same
                // length closes the block (CommonMark §4.5).
                if ($marker !== null && $marker[0] === $fence[0] && $marker[1] >= $fence[1]) {
                    $fence = null;
                }

                continue;
            }

            if ($marker !== null) {
                $fence = $marker;

                continue;
            }

            if ($this->lineHasRawHtml($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{string, int}|null the fence character and its length
     */
    private function fenceMarker(string $line): ?array
    {
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $matches) !== 1) {
            return null;
        }

        return [$matches[1][0], strlen($matches[1])];
    }

    private function lineHasRawHtml(string $line): bool
    {
        $withoutCode = preg_replace('/(`+)(?:.|\n)*?\1/u', '', $line) ?? $line;

        $withoutAutolinks = preg_replace(
            [
                // <scheme:...> absolute URI autolink
                '/<[A-Za-z][A-Za-z0-9+.\-]{1,31}:[^<>\x00-\x20]*>/u',
                // <local@domain> email autolink
                '/<[^\s<>@]+@[^\s<>@]+>/u',
            ],
            '',
            $withoutCode,
        ) ?? $withoutCode;

        // What is left that opens like a tag, a closing tag, a comment, a
        // processing instruction or a declaration is raw HTML.
        return preg_match('/<[A-Za-z!\/?]/u', $withoutAutolinks) === 1;
    }
}
