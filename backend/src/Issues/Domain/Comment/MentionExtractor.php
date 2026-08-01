<?php

declare(strict_types=1);

namespace Sova\Issues\Domain\Comment;

/**
 * Reads the structured mentions out of a comment's CommonMark source.
 *
 * A mention is an ordinary Markdown link whose destination is
 * `sova:user/<tenant membership uuid>`, for example
 * `[@Jana](sova:user/0195c0de-...)`. Keeping the reference inside the source
 * means the text and the addressed people can never disagree, and the display
 * name in the label is cosmetic — renaming a member does not re-address an old
 * comment.
 *
 * Extraction is purely syntactic. Whether the mentioned member exists, is
 * active and may actually see the issue is decided later, against the caller's
 * scope, because a mention must never grant access.
 */
final class MentionExtractor
{
    private const string PATTERN =
        '/\]\(\s*<?sova:user\/([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}'
        . '-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})>?\s*\)/u';

    /**
     * @return list<string> distinct membership identifiers, lowercased, in the
     *                     order they appear
     */
    public function extract(string $body): array
    {
        if (preg_match_all(self::PATTERN, $body, $matches) < 1) {
            return [];
        }

        $seen = [];

        foreach ($matches[1] as $identifier) {
            $seen[strtolower($identifier)] = true;
        }

        return array_keys($seen);
    }
}
