<?php

declare(strict_types=1);

namespace Framework\Embed;

/**
 * Wraps any PSR-16 cache (Psr\SimpleCache\CacheInterface) without depending on
 * psr/simple-cache: the object only needs get, set, and delete.
 */
final class Psr16Cache implements Cache
{
    public function __construct(private object $cache) {}

    public function get(string $key): ?string
    {
        $value = $this->cache->get($key);

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->cache->set($key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        $this->cache->delete($key);
    }
}
