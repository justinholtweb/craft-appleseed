<?php

namespace justinholtweb\appleseed\helpers;

class LinkText
{
    /** @var int Max characters the `linkText` column holds (see the Install migration). */
    public const MAX_LENGTH = 500;

    /**
     * Collapse a link's text down to something that fits the `linkText` column.
     *
     * A link can wrap a whole block -- a card with a heading and a summary, say -- so its
     * text content arrives with newlines and indentation and can run to thousands of
     * characters, which overflows the column. Collapse the whitespace and cap the length;
     * anything past the opening words is noise in the results table anyway.
     */
    public static function normalize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        // Includes non-breaking spaces, which rich text is full of
        $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_LENGTH - 1) . '…';
        }

        return $text;
    }
}
