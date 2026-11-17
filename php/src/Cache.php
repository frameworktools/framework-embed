<?php

declare(strict_types=1);

namespace Framework\Embed;

/**
 * The cache the client needs: a subset of PSR-16. Adapt a CMS cache (WordPress
 * transients, Drupal cache bins, Laravel or Statamic Cache) to it, or wrap a
 * PSR-16 cache in Psr16Cache.
 */
interface Cache
{
    /** The stored string, or null when missing or expired. */
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttl): void;

    public function delete(string $key): void;
}
