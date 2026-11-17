<?php

declare(strict_types=1);

namespace Framework\Embed;

/** An in-memory cache for one request or for tests. */
final class ArrayCache implements Cache
{
    /** @var array<string, array{0: string, 1: int}> */
    private array $items = [];

    /** @param (callable(): int)|null $clock */
    public function __construct(private $clock = null) {}

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    public function get(string $key): ?string
    {
        $item = $this->items[$key] ?? null;
        if ($item === null || $item[1] <= $this->now()) {
            unset($this->items[$key]);

            return null;
        }

        return $item[0];
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->items[$key] = [$value, $this->now() + $ttl];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
