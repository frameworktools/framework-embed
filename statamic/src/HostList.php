<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed;

use Framework\Embed\UrlMatcher;

/**
 * Validates allowed-host entries with the core UrlMatcher. A bare host name
 * allows https on the default port. An origin such as http://localhost:8000
 * allows exactly that scheme, host, and port. IP addresses, paths, user info,
 * and other schemes are rejected.
 */
final class HostList
{
    /** The entry in canonical form, or null when UrlMatcher would not accept it. */
    public static function normalize(string $entry): ?string
    {
        $entry = strtolower(trim($entry));
        if ($entry === '') {
            return null;
        }
        $bare = ! str_contains($entry, '://');
        $origin = (new UrlMatcher([$entry]))->allowedOrigin(($bare ? 'https://' . $entry : rtrim($entry, '/')) . '/');
        if ($origin === null) {
            return null;
        }
        // https on the default port is the bare host name.
        $host = substr($origin, strlen('https://'));

        return str_starts_with($origin, 'https://') && ! str_contains($host, ':') ? $host : $origin;
    }

    /**
     * framework.pub plus the valid configured entries, and the rejected entries.
     *
     * @param iterable<mixed> $entries
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function resolve(iterable $entries): array
    {
        $hosts = UrlMatcher::DEFAULT_HOSTS;
        $invalid = [];
        foreach ($entries as $entry) {
            $host = is_string($entry) ? self::normalize($entry) : null;
            if ($host === null) {
                if (is_string($entry) && trim($entry) !== '') {
                    $invalid[] = trim($entry);
                }
            } elseif (! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return [$hosts, $invalid];
    }
}
