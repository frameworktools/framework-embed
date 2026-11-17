<?php

declare(strict_types=1);

namespace Framework\Embed;

use Framework\Embed\Http\Response;
use Framework\Embed\Http\StreamFetcher;

/**
 * Fetches embed documents from allowed hosts only, caches them no longer than
 * their Cache-Control allows, and forgets them when the server answers 404.
 *
 * The fetcher is any callable `fn (string $url): Response` (an array with
 * status, headers, and body also works). It must not follow redirects.
 * WordPress, for example: fn ($url) => wp_remote_get($url, ['redirection' => 0]) mapped to a Response.
 */
final class Client
{
    /** @var callable(string): (Response|array) */
    private $fetcher;

    private UrlMatcher $matcher;

    /**
     * @param list<string> $hosts Bare host names (https) or origins such as http://localhost:8000.
     * @param int $maxTtl An upper bound on how long a document stays cached, in seconds.
     */
    public function __construct(
        array $hosts = UrlMatcher::DEFAULT_HOSTS,
        ?callable $fetcher = null,
        private ?Cache $cache = null,
        private int $maxTtl = 3600,
        private string $cachePrefix = 'framework-embed:',
    ) {
        $this->matcher = new UrlMatcher($hosts);
        $this->fetcher = $fetcher ?? new StreamFetcher();
    }

    public function matcher(): UrlMatcher
    {
        return $this->matcher;
    }

    /**
     * The embed document for a pasted artifact URL (/d/{key}, /@handle/project/slug,
     * either with /versions/{n}) or an embed API URL. Null when the artifact is gone.
     *
     * @throws EmbedException
     */
    public function resolve(string $url): ?array
    {
        $match = $this->matcher->match($url);
        if ($match === null) {
            throw new EmbedException(EmbedException::NOT_ALLOWED, 'The URL is not a Framework artifact on an allowed host.');
        }

        return $this->get($match->requestUrl());
    }

    /**
     * The embed document at an embed API URL (/api/embed/v1/artifacts/{key}[/versions/{n}]).
     *
     * @throws EmbedException
     */
    public function fetch(string $embedUrl): ?array
    {
        $match = $this->matcher->match($embedUrl);
        if ($match === null || $match->type !== 'embed') {
            throw new EmbedException(EmbedException::NOT_ALLOWED, 'The URL is not an embed API URL on an allowed host.');
        }

        return $this->get($match->requestUrl());
    }

    /** HTML for an artifact URL, or null when it is gone. */
    public function render(string $url): ?string
    {
        $doc = $this->resolve($url);

        return $doc === null ? null : Renderer::render($doc);
    }

    private function get(string $requestUrl): ?array
    {
        $key = $this->cachePrefix . sha1($requestUrl);
        $cached = $this->cache?->get($key);
        if ($cached !== null) {
            $doc = Document::decode($cached);
            if ($doc !== null) {
                return $doc;
            }
            $this->cache->delete($key);
        }

        $response = ($this->fetcher)($requestUrl);
        if (is_array($response)) {
            $response = new Response((int) ($response['status'] ?? 0), (array) ($response['headers'] ?? []), (string) ($response['body'] ?? ''));
        }
        if (! $response instanceof Response) {
            throw new EmbedException(EmbedException::HTTP, 'The fetcher returned no response.');
        }
        if ($response->status === 404 || $response->status === 410) {
            $this->cache?->delete($key);

            return null;
        }
        if ($response->status !== 200) {
            throw new EmbedException(EmbedException::HTTP, 'The embed request failed with status ' . $response->status . '.');
        }
        $doc = Document::decode($response->body);
        if ($doc === null) {
            throw new EmbedException(EmbedException::INVALID, 'The response is not a framework.embed/v1 document.');
        }
        $ttl = min($this->maxTtl, self::ttl($response));
        if ($this->cache !== null && $ttl > 0) {
            $this->cache->set($key, $response->body, $ttl);
        }

        return $doc;
    }

    /** Seconds a response may be cached: max-age less Age, or 0 for no-store, no-cache, private, or none. */
    public static function ttl(Response $response): int
    {
        $control = strtolower($response->header('cache-control') ?? '');
        if ($control === '' || preg_match('/(^|[\s,])(no-store|no-cache|private)([\s,=]|$)/', $control) === 1) {
            return 0;
        }
        if (preg_match('/(^|[\s,])max-age\s*=\s*"?(\d+)"?/', $control, $m) !== 1) {
            return 0;
        }
        $age = (int) ($response->header('age') ?? 0);

        return max(0, (int) $m[2] - max(0, $age));
    }
}
