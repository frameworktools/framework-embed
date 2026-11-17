<?php

/**
 * Test only. A fetcher that answers embed requests from spec/fixtures, shared
 * by the WordPress, Drupal, and Statamic test harnesses. Never use it on a site.
 *
 * Routes (the inner URL of /api/embed/v1/resolve?url=… is matched too):
 *   /d/{name}, /@handle/project/{name}, /api/embed/v1/artifacts/{name or key}
 *   each with an optional /versions/{n}
 *
 * {name} is a fixture file name; {key} is the fixture's "key". Unversioned
 * routes answer with Cache-Control max-age=300, versioned ones max-age=3600.
 *
 * $gone lists names that answer 404. $down lists names that fail like an
 * unreachable server. $bumped lists names that have a version 2: version 1
 * then reports latest_version 2, and unversioned routes answer version 2.
 */

declare(strict_types=1);

namespace FrameworkEmbedTesting;

use Framework\Embed\EmbedException;
use Framework\Embed\Http\Response;

final class FixtureServer
{
    /** @var array<string, string>|null key => fixture name */
    private ?array $keys = null;

    /**
     * @param list<string> $gone
     * @param list<string> $down
     * @param list<string> $bumped
     * @param (callable(string): void)|null $onRequest Called with each requested URL.
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $gone = [],
        private readonly array $down = [],
        private readonly array $bumped = [],
        private $onRequest = null,
    ) {}

    public function __invoke(string $url): Response
    {
        if ($this->onRequest !== null) {
            ($this->onRequest)($url);
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $target = isset($params['url']) && is_string($params['url']) ? $params['url'] : $url;
        $path = (string) parse_url($target, PHP_URL_PATH);
        if (preg_match('#^/(?:d|api/embed/v1/artifacts|@[^/]+/[^/]+)/([A-Za-z0-9_.-]+)(?:/versions/(\d+))?/?$#', $path, $m) !== 1) {
            return $this->notFound();
        }
        $name = $this->name($m[1]);
        $version = isset($m[2]) ? (int) $m[2] : null;
        if ($name === null) {
            return $this->notFound();
        }
        if (in_array($name, $this->down, true)) {
            throw new EmbedException(EmbedException::HTTP, 'Simulated network failure.');
        }
        if (in_array($name, $this->gone, true)) {
            return $this->notFound();
        }

        $body = (string) file_get_contents("{$this->directory}/{$name}.json");
        if (in_array($name, $this->bumped, true)) {
            $body = $this->bump($body, $version ?? 2);
        }

        return new Response(200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=' . ($version === null ? 300 : 3600),
        ], $body);
    }

    /** The fixture name for a route segment that is a name or a key. */
    private function name(string $segment): ?string
    {
        if (is_file("{$this->directory}/" . basename($segment) . '.json')) {
            return basename($segment);
        }
        if ($this->keys === null) {
            $this->keys = [];
            foreach (glob("{$this->directory}/*.json") ?: [] as $file) {
                $doc = json_decode((string) file_get_contents($file), true);
                if (is_array($doc) && isset($doc['key']) && is_string($doc['key'])) {
                    $this->keys[$doc['key']] = basename($file, '.json');
                }
            }
        }

        return $this->keys[$segment] ?? null;
    }

    /** The fixture as version $version of two. */
    private function bump(string $body, int $version): string
    {
        $doc = json_decode($body);
        $doc->latest_version = 2;
        if ($version === 2) {
            $doc->version = 2;
            foreach (['url', 'embed_url'] as $field) {
                if (isset($doc->{$field}) && is_string($doc->{$field})) {
                    $doc->{$field} = preg_replace('#/versions/\d+$#', '/versions/2', $doc->{$field});
                }
            }
            $doc->title = ($doc->title ?? 'Untitled') . ' (version 2)';
        }

        return (string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function notFound(): Response
    {
        return new Response(404, ['Cache-Control' => 'no-store']);
    }
}
