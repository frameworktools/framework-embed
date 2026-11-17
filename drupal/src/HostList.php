<?php

declare(strict_types=1);

namespace Drupal\framework_embed;

use Framework\Embed\UrlMatcher;

/**
 * Validates allowed-host entries with the core UrlMatcher.
 *
 * A bare host name allows https on the default port. An origin such as
 * http://localhost:8000 allows exactly that scheme, host, and port. IP
 * addresses, paths, user info, and other schemes are rejected.
 */
final class HostList {

  /**
   * The entry in canonical form, or NULL when UrlMatcher would not accept it.
   */
  public static function normalize(string $entry): ?string {
    $entry = strtolower(trim($entry));
    if ($entry === '') {
      return NULL;
    }
    $bare = !str_contains($entry, '://');
    $origin = (new UrlMatcher([$entry]))->allowedOrigin(($bare ? 'https://' . $entry : rtrim($entry, '/')) . '/');
    if ($origin === NULL) {
      return NULL;
    }
    // https on the default port is the bare host name.
    $host = substr($origin, strlen('https://'));

    return str_starts_with($origin, 'https://') && !str_contains($host, ':') ? $host : $origin;
  }

  /**
   * Splits text into entries and sorts them into valid and invalid.
   *
   * @return array{0: list<string>, 1: list<string>}
   *   Normalized valid hosts other than the defaults, and rejected entries.
   */
  public static function parse(string $text): array {
    $valid = [];
    $invalid = [];
    foreach (preg_split('/[\r\n,]+/', $text) ?: [] as $line) {
      if (trim($line) === '') {
        continue;
      }
      $host = self::normalize($line);
      if ($host === NULL) {
        $invalid[] = trim($line);
      }
      elseif (!in_array($host, UrlMatcher::DEFAULT_HOSTS, TRUE)) {
        $valid[] = $host;
      }
    }

    return [array_values(array_unique($valid)), $invalid];
  }

}
