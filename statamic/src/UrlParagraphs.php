<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed;

/**
 * Finds paragraphs that hold nothing but a URL, as Bard and Markdown produce
 * when an author pastes a link on its own line: <p>URL</p> or
 * <p><a href="URL">URL</a></p>.
 */
final class UrlParagraphs
{
    /**
     * Replaces each URL paragraph for which $replace returns a string.
     *
     * @param callable(string): ?string $replace Takes the decoded URL; returns replacement HTML, or null to keep it.
     */
    public static function replace(string $html, callable $replace): string
    {
        if (stripos($html, '<p') === false) {
            return $html;
        }

        return preg_replace_callback(
            '#<p(?:\s[^>]*)?>((?:(?!</?p[\s>]).)*?)</p>#is',
            static function (array $m) use ($replace): string {
                $url = self::url($m[1]);

                return $url === null ? $m[0] : ($replace($url) ?? $m[0]);
            },
            $html,
        ) ?? $html;
    }

    /** The URL a paragraph's inner HTML consists of, or null. */
    public static function url(string $inner): ?string
    {
        $inner = trim(str_replace(['&nbsp;', "\u{00A0}"], ' ', $inner));
        if (preg_match('#^<a\s[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>\s*(.*?)\s*</a>$#is', $inner, $m) === 1) {
            $href = self::decode($m[2]);

            return $href === self::decode($m[3]) && self::looksLikeUrl($href) ? $href : null;
        }
        if (str_contains($inner, '<')) {
            return null;
        }
        $text = self::decode($inner);

        return self::looksLikeUrl($text) ? $text : null;
    }

    private static function decode(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function looksLikeUrl(string $value): bool
    {
        return preg_match('#^https?://\S+$#iD', $value) === 1;
    }
}
