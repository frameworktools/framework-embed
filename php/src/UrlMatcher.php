<?php

declare(strict_types=1);

namespace Framework\Embed;

/**
 * Host allow-list and artifact URL matching. A port of js/src/url.js.
 *
 * Allowed hosts are bare host names, which allow https only, or origins such as
 * `http://localhost:8000`, which allow exactly that scheme, host, and port.
 * IP literals, user info, percent-encoded or trailing-dot hosts, and
 * non-default ports that are not listed never match.
 */
final class UrlMatcher
{
    public const DEFAULT_HOSTS = ['framework.pub'];

    private const URL = '/^([a-z][a-z0-9+.-]*):\/\/([^\/?#]*)([^?#]*)(\?[^#]*)?(#.*)?$/iD';

    private const AUTHORITY = '/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*)(?::([0-9]{1,5}))?$/iD';

    private const DEFAULT_PORTS = ['http' => '80', 'https' => '443'];

    private const KEY = '([A-Za-z0-9_-]{1,64})';

    private const NAME = '([A-Za-z0-9_.-]{1,200})';

    private const VERSION = '([1-9][0-9]{0,8})';

    /** @var list<array{scheme: string, host: string, port: ?string}> */
    private array $allowed = [];

    /** @param list<string> $hosts */
    public function __construct(array $hosts = self::DEFAULT_HOSTS)
    {
        foreach ($hosts as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $parsed = self::parse(str_contains($entry, '://') ? $entry : 'https://' . $entry);
            if ($parsed !== null && $parsed['path'] === '/' && $parsed['query'] === '') {
                $this->allowed[] = $parsed;
            }
        }
    }

    /** @return array{scheme: string, host: string, port: ?string, path: string, query: string}|null */
    private static function parse(mixed $url): ?array
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048 || preg_match('/[^\x21-\x7e]|\\\\/', $url) === 1) {
            return null;
        }
        if (preg_match(self::URL, $url, $parts) !== 1) {
            return null;
        }
        $scheme = strtolower($parts[1]);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }
        if (preg_match(self::AUTHORITY, $parts[2], $authority) !== 1) {
            return null;
        }
        $host = strtolower($authority[1]);
        // A host whose last label is numeric is an IP address to a browser.
        if (preg_match('/(^|\.)(\d+|0x[0-9a-f]*)$/D', $host) === 1) {
            return null;
        }
        $port = isset($authority[2]) && $authority[2] !== '' ? $authority[2] : null;
        if ($port !== null) {
            $number = (int) $port;
            if ($number < 1 || $number > 65535) {
                return null;
            }
            $port = (string) $number === self::DEFAULT_PORTS[$scheme] ? null : (string) $number;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => ($parts[3] ?? '') !== '' ? $parts[3] : '/', 'query' => $parts[4] ?? ''];
    }

    /** The URL's origin when its scheme, host, and port match an allowed host exactly, else null. */
    public function allowedOrigin(string $url): ?string
    {
        $parsed = self::parse($url);
        if ($parsed === null) {
            return null;
        }
        foreach ($this->allowed as $allowed) {
            if ($allowed['scheme'] === $parsed['scheme'] && $allowed['host'] === $parsed['host'] && $allowed['port'] === $parsed['port']) {
                return $parsed['scheme'] . '://' . $parsed['host'] . ($parsed['port'] === null ? '' : ':' . $parsed['port']);
            }
        }

        return null;
    }

    public function isAllowed(string $url): bool
    {
        return $this->allowedOrigin($url) !== null;
    }

    /** What an artifact URL on an allowed host points at, or null. */
    public function match(string $url): ?ArtifactUrl
    {
        $origin = $this->allowedOrigin($url);
        if ($origin === null) {
            return null;
        }
        $parsed = self::parse($url);
        $path = $parsed['path'];
        // The one query option an artifact URL keeps: output=none, a code snippet without its output
        $option = in_array('output=none', explode('&', substr($parsed['query'], 1)), true) ? '?output=none' : '';
        $k = self::KEY;
        $n = self::NAME;
        $v = self::VERSION;
        if (preg_match("#^/api/embed/v1/artifacts/{$k}(?:/versions/{$v})?$#D", $path, $m) === 1) {
            return new ArtifactUrl('embed', $origin, $origin . $path . $option, key: $m[1], version: isset($m[2]) ? (int) $m[2] : null);
        }
        if (preg_match("#^/d/{$k}(?:/versions/{$v})?/?$#D", $path, $m) === 1) {
            return new ArtifactUrl('key', $origin, $origin . $path . $option, key: $m[1], version: isset($m[2]) ? (int) $m[2] : null);
        }
        if (preg_match("#^/@{$n}/{$n}/{$n}(?:/versions/{$v})?/?$#D", $path, $m) === 1) {
            foreach ([$m[1], $m[2], $m[3]] as $part) {
                if ($part === '.' || $part === '..') {
                    return null;
                }
            }

            return new ArtifactUrl('path', $origin, $origin . $path . $option, handle: $m[1], project: $m[2], slug: $m[3], version: isset($m[4]) ? (int) $m[4] : null);
        }

        return null;
    }

    /** The embed API URL to request, or null when the URL is not an artifact on an allowed host. */
    public function requestUrl(string $url): ?string
    {
        return $this->match($url)?->requestUrl();
    }
}
