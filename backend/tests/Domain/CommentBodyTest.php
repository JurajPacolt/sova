<?php

declare(strict_types=1);

namespace Sova\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sova\Issues\Domain\Comment\CommentBodyValidator;
use Sova\Issues\Domain\Comment\MentionExtractor;

/**
 * The comment body is CommonMark source that the backend never renders. These
 * tests pin the boundary rule: raw HTML is refused where it could become
 * markup, and left alone where CommonMark guarantees it cannot.
 */
final class CommentBodyTest extends TestCase
{
    #[DataProvider('acceptedBodies')]
    public function testAcceptedBody(string $body): void
    {
        self::assertSame([], (new CommentBodyValidator())->violations($body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedBodies(): iterable
    {
        yield 'plain text' => ['A plain comment.'];
        yield 'markdown emphasis' => ['This is **bold** and _italic_.'];
        yield 'link' => ['See [the docs](https://example.test/docs).'];
        yield 'autolink' => ['See <https://example.test/docs> for details.'];
        yield 'email autolink' => ['Write to <ops@example.test>.'];
        yield 'less than as maths' => ['The result is a < b and c > d.'];
        yield 'html inside a fenced block' => [
            "Reproduce with:\n\n```html\n<div onclick=\"x\">boom</div>\n```\n",
        ];
        yield 'html inside a tilde fence' => [
            "~~~\n<script>alert(1)</script>\n~~~\n",
        ];
        yield 'html inside an inline code span' => ['Use `<br>` for a line break.'];
        yield 'mention' => ['Ping [@Jana](sova:user/0195c0de-1111-7000-8000-000000000001).'];
    }

    #[DataProvider('rejectedBodies')]
    public function testRejectedBody(string $body, string $violation): void
    {
        self::assertContains(
            $violation,
            (new CommentBodyValidator())->violations($body),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedBodies(): iterable
    {
        yield 'empty' => ['', CommentBodyValidator::EMPTY_BODY];
        yield 'whitespace only' => ["  \n\t ", CommentBodyValidator::EMPTY_BODY];
        yield 'too long' => [
            str_repeat('a', CommentBodyValidator::MAX_LENGTH + 1),
            CommentBodyValidator::TOO_LONG,
        ];
        yield 'script tag' => ['<script>alert(1)</script>', CommentBodyValidator::RAW_HTML];
        yield 'image with handler' => [
            '<img src=x onerror=alert(1)>',
            CommentBodyValidator::RAW_HTML,
        ];
        yield 'closing tag' => ['text </div>', CommentBodyValidator::RAW_HTML];
        yield 'html comment' => ['<!-- hidden -->', CommentBodyValidator::RAW_HTML];
        yield 'processing instruction' => ['<?php echo 1; ?>', CommentBodyValidator::RAW_HTML];
        yield 'after a closed fence' => [
            "```\nsafe\n```\n<script>alert(1)</script>",
            CommentBodyValidator::RAW_HTML,
        ];
        yield 'anchor with javascript scheme' => [
            '<a href="javascript:alert(1)">x</a>',
            CommentBodyValidator::RAW_HTML,
        ];
    }

    /**
     * An unclosed fence swallows the rest of the body, which is exactly how
     * CommonMark reads it too — so nothing after it can become markup.
     */
    public function testUnclosedFenceKeepsTheRestInert(): void
    {
        self::assertSame(
            [],
            (new CommentBodyValidator())->violations("```\n<script>alert(1)</script>"),
        );
    }

    public function testMentionsAreExtractedAsDistinctLowercasedIdentifiers(): void
    {
        $body = 'Ping [@Jana](sova:user/0195C0DE-1111-7000-8000-000000000001)'
            . ' and [@Peter](sova:user/0195c0de-1111-7000-8000-000000000002),'
            . ' and [@Jana again](sova:user/0195c0de-1111-7000-8000-000000000001).';

        self::assertSame(
            [
                '0195c0de-1111-7000-8000-000000000001',
                '0195c0de-1111-7000-8000-000000000002',
            ],
            (new MentionExtractor())->extract($body),
        );
    }

    #[DataProvider('nonMentions')]
    public function testTextThatIsNotAStructuredMentionYieldsNothing(string $body): void
    {
        self::assertSame([], (new MentionExtractor())->extract($body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonMentions(): iterable
    {
        yield 'plain at sign' => ['Ping @jana about this.'];
        yield 'no comment at all' => ['Nothing to see.'];
        yield 'wrong scheme' => ['[@Jana](user/0195c0de-1111-7000-8000-000000000001)'];
        yield 'not a uuid' => ['[@Jana](sova:user/not-a-uuid)'];
        yield 'issue reference' => ['[SOVA-1](sova:issue/SOVA-1)'];
    }
}
