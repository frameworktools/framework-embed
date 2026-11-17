<?php

declare(strict_types=1);

namespace Drupal\framework_embed;

/**
 * Finds paragraphs that hold nothing but a URL, as editors produce when they
 * paste a link on its own line: <p>URL</p> or <p><a href="URL">URL</a></p>.
 */
final class UrlParagraphs {

  /**
   * Replaces each URL paragraph for which $replace returns a string.
   *
   * @param string $html
   *   Filtered HTML.
   * @param callable(string): ?string $replace
   *   Takes the decoded URL; returns the replacement HTML, or NULL to keep it.
   */
  public static function replace(string $html, callable $replace): string {
    if (stripos($html, '<p') === FALSE) {
      return $html;
    }

    return preg_replace_callback(
      '#<p(?:\s[^>]*)?>((?:(?!</?p[\s>]).)*?)</p>#is',
      static function (array $m) use ($replace): string {
        $url = self::url($m[1]);
        if ($url === NULL) {
          return $m[0];
        }

        return $replace($url) ?? $m[0];
      },
      $html,
    ) ?? $html;
  }

  /**
   * The URL a paragraph's inner HTML consists of, or NULL.
   */
  public static function url(string $inner): ?string {
    $inner = trim(str_replace(['&nbsp;', "\u{00A0}"], ' ', $inner));
    if (preg_match('#^<a\s[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>\s*(.*?)\s*</a>$#is', $inner, $m) === 1) {
      $href = self::decode($m[2]);
      $text = self::decode($m[3]);

      return $href === $text && self::looksLikeUrl($href) ? $href : NULL;
    }
    if (str_contains($inner, '<')) {
      return NULL;
    }
    $text = self::decode($inner);

    return self::looksLikeUrl($text) ? $text : NULL;
  }

  private static function decode(string $value): string {
    return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

  private static function looksLikeUrl(string $value): bool {
    return preg_match('#^https?://\S+$#iD', $value) === 1;
  }

}
