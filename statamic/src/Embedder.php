<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed;

use Closure;
use Framework\Embed\Client;
use Framework\Embed\Document;
use Framework\Embed\EmbedException;
use Framework\Embed\Http\Response;
use Framework\Embed\Renderer;
use Framework\Embed\UrlMatcher;
use FrameworkCloud\StatamicEmbed\Jobs\RefreshEmbed;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches and renders artifacts through the framework-cloud/embed core.
 *
 * Stored tier: the framework_artifact fieldtype keeps the embed document in
 * the entry; renderStored() renders it without contacting the server.
 *
 * Live tier: {{ framework:artifact url="…" }} and the framework_embeds
 * modifier render from the cache and never wait on the network. Without a
 * copy they render a link and dispatch a fetch that runs after the response
 * (or on the queue). An expired copy renders while it is refreshed. A 404
 * drops the copy and the link stays.
 */
class Embedder
{
    public const GONE = 'gone';

    /** Seconds an expired copy may still render while it is refreshed. */
    public const STALE_TTL = 604800;

    /** Seconds between fetch attempts for one URL. */
    public const RETRY_TTL = 300;

    /** Seconds a 404 is remembered before the URL is tried again. */
    public const GONE_TTL = 3600;

    /** Whether a rendered figure on this request contains a chart. */
    private bool $sawChart = false;

    /** @var array<string, array|EmbedException> Stored-tier fetches made during this request. */
    private array $fetched = [];

    /**
     * @param list<string> $hosts Allowed hosts, framework.pub included.
     * @param callable $fetcher Takes an embed API URL, returns a Framework\Embed\Http\Response.
     * @param Closure(): bool $canSeeNotices Whether the current visitor may see why an embed is missing.
     * @param 'after_response'|'queue' $refresh How background fetches run.
     */
    public function __construct(
        private readonly array $hosts,
        private $fetcher,
        private readonly Repository $cache,
        private readonly Closure $canSeeNotices,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $maxTtl = 3600,
        private readonly string $refresh = 'after_response',
    ) {}

    public function matcher(): UrlMatcher
    {
        return new UrlMatcher($this->hosts);
    }

    public function isArtifactUrl(string $url): bool
    {
        return $this->matcher()->match($url) !== null;
    }

    public function sawChart(): bool
    {
        return $this->sawChart;
    }

    /**
     * Requests one embed document now, bypassing every cache.
     *
     * @return array{doc: ?array, body: string, ttl: int} doc is null on 404 or 410.
     * @throws EmbedException
     */
    public function request(string $url): array
    {
        $fetcher = $this->fetcher;
        $response = null;
        $capture = static function (string $requestUrl) use ($fetcher, &$response) {
            $answer = $fetcher($requestUrl);
            if (is_array($answer)) {
                $answer = new Response((int) ($answer['status'] ?? 0), (array) ($answer['headers'] ?? []), (string) ($answer['body'] ?? ''));
            }

            return $response = $answer;
        };
        $doc = (new Client($this->hosts, $capture))->resolve($url);

        return [
            'doc' => $doc,
            'body' => $response instanceof Response ? $response->body : '',
            'ttl' => $response instanceof Response ? min(max(0, $this->maxTtl), Client::ttl($response)) : 0,
        ];
    }

    /** The URL that answers with the newest version of the artifact at $url. */
    public function newestUrl(string $url): ?string
    {
        $match = $this->matcher()->match($url);
        if ($match === null) {
            return null;
        }
        if ($match->type === 'path') {
            return "{$match->origin}/@{$match->handle}/{$match->project}/{$match->slug}";
        }

        return $match->origin . ($match->type === 'embed' ? '/api/embed/v1/artifacts/' : '/d/') . $match->key;
    }

    // ---- Stored tier.

    /**
     * Fetches a document to store, pinning its version URL. Memoized per
     * request, so validation and processing share one fetch.
     *
     * @param string $url The URL the author entered.
     * @param string|null $target What to request; defaults to $url.
     * @return array{url: string, embed_url: ?string, document: string, fetched_at: string, removed: bool}
     * @throws EmbedException reason not_allowed, http, invalid, or gone.
     */
    public function fetchForStorage(string $url, ?string $target = null): array
    {
        $url = trim($url);
        $target = trim($target ?? $url);
        $memo = $url . "\n" . $target;
        if (! isset($this->fetched[$memo])) {
            try {
                $answer = $this->request($target);
                if ($answer['doc'] === null) {
                    throw new EmbedException(self::GONE, 'The artifact is private, password-protected, or deleted.');
                }
                $embedUrl = is_string($answer['doc']['embed_url'] ?? null) ? $answer['doc']['embed_url'] : '';
                $pinned = $this->matcher()->match($embedUrl);
                $this->fetched[$memo] = [
                    'url' => $url,
                    'embed_url' => $pinned !== null && $pinned->type === 'embed' ? $embedUrl : null,
                    'document' => $answer['body'],
                    'fetched_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'removed' => false,
                ];
            } catch (EmbedException $e) {
                $this->fetched[$memo] = $e;
            }
        }
        if ($this->fetched[$memo] instanceof EmbedException) {
            throw $this->fetched[$memo];
        }

        return $this->fetched[$memo];
    }

    /** A message for authors about a failed storage fetch. */
    public static function message(EmbedException $e): string
    {
        return match ($e->reason) {
            EmbedException::NOT_ALLOWED => __('This URL is not a Framework artifact on an allowed host. Add self-hosted Framework Cloud servers in config/framework-embed.php.'),
            self::GONE => __('This Framework artifact is private, password-protected, or deleted.'),
            default => __('The Framework Cloud server could not be reached, or it returned an invalid embed response. Try again later.'),
        };
    }

    /**
     * HTML for a stored value. Never contacts the server. Image artifacts
     * point at the image URL in the document; no file is copied.
     */
    public function renderStored(mixed $value): string
    {
        if (! is_array($value)) {
            return is_string($value) && trim($value) !== '' ? $this->link(trim($value)) : '';
        }
        $url = is_string($value['url'] ?? null) ? $value['url'] : '';
        $doc = is_string($value['document'] ?? null) && $value['document'] !== '' ? Document::decode($value['document']) : null;
        if ($doc !== null) {
            return $this->figure($doc) ?? $this->link($url);
        }
        if (! empty($value['removed'])) {
            return $this->link($url) . $this->notice('This artifact is no longer available on the Framework Cloud server, so its stored copy was removed. Visitors see a link.', $url);
        }

        return $this->link($url);
    }

    // ---- Live tier.

    private function key(string $kind, string $url): string
    {
        return "framework_embed:{$kind}:" . sha1(trim($url));
    }

    /** HTML for a URL from the cache, dispatching a fetch when needed. */
    public function render(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (! $this->isArtifactUrl($url)) {
            return $this->link($url) . $this->notice('This URL is not a Framework artifact on an allowed host. Add self-hosted Framework Cloud servers in config/framework-embed.php.', $url);
        }

        $entry = $this->cache->get($this->key('live', $url));
        if (is_array($entry) && isset($entry['body'], $entry['expires'])) {
            $doc = Document::decode((string) $entry['body']);
            $html = $doc === null ? null : $this->figure($doc);
            if ($html !== null) {
                if ((int) $entry['expires'] <= now()->getTimestamp()) {
                    $this->schedule($url);
                }

                return $html;
            }
        }

        if ($this->cache->has($this->key('gone', $url))) {
            return $this->link($url) . $this->notice('This Framework artifact is private, password-protected, or deleted. Visitors see a link.', $url);
        }
        $this->schedule($url);

        return $this->link($url) . $this->notice('This artifact is being fetched in the background. Visitors see a link until the fetch completes.', $url);
    }

    /** Dispatches a fetch, at most once per RETRY_TTL for a URL. */
    public function schedule(string $url): void
    {
        if (! $this->cache->add($this->key('pending', $url), true, self::RETRY_TTL)) {
            return;
        }
        $job = new RefreshEmbed($url);
        if ($this->refresh === 'queue') {
            dispatch($job);
        } else {
            dispatch($job)->afterResponse();
        }
    }

    public function isFresh(string $url): bool
    {
        $entry = $this->cache->get($this->key('live', $url));

        return is_array($entry) && (int) ($entry['expires'] ?? 0) > now()->getTimestamp();
    }

    /**
     * Fetches a URL and updates its cached copy.
     *
     * @return string fresh, uncacheable, gone, or error. On error the old copy stays.
     */
    public function refresh(string $url): string
    {
        $url = trim($url);
        try {
            $answer = $this->request($url);
        } catch (EmbedException $e) {
            $this->logger?->warning('Framework embed fetch failed for {url}: {message}', ['url' => $url, 'message' => $e->getMessage()]);

            return 'error';
        }
        $this->cache->forget($this->key('pending', $url));
        if ($answer['doc'] === null) {
            $this->cache->forget($this->key('live', $url));
            $this->cache->put($this->key('gone', $url), true, self::GONE_TTL);

            return 'gone';
        }
        $this->cache->forget($this->key('gone', $url));
        if ($answer['ttl'] <= 0) {
            $this->cache->forget($this->key('live', $url));

            return 'uncacheable';
        }
        $this->cache->put($this->key('live', $url), ['body' => $answer['body'], 'expires' => now()->getTimestamp() + $answer['ttl']], $answer['ttl'] + self::STALE_TTL);

        return 'fresh';
    }

    /** Converts each paragraph that holds only an artifact URL on an allowed host. */
    public function convertParagraphs(string $html): string
    {
        return UrlParagraphs::replace($html, fn (string $url): ?string => $this->isArtifactUrl($url) ? $this->render($url) : null);
    }

    // ---- Markup.

    private function figure(array $doc): ?string
    {
        try {
            $html = Renderer::render($doc);
        } catch (Throwable) {
            return null;
        }
        // The script draws charts, opens variable tips on hover, and shows a snippet's buttons
        if (str_contains($html, 'data-fw-embed-chart') || str_contains($html, 'fw-embed__term') || str_contains($html, 'fw-embed__snippet')) {
            $this->sawChart = true;
        }

        return $html;
    }

    private function link(string $url): string
    {
        if (preg_match('#^https?://[^\s"\'<>\\\\`]+$#iD', $url) !== 1) {
            return '';
        }
        $e = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<p><a href="' . $e . '">' . $e . '</a></p>';
    }

    private function notice(string $message, string $url): string
    {
        if (! ($this->canSeeNotices)()) {
            return '';
        }

        return '<p class="framework-embed-notice" role="note" style="border-left:4px solid #d63638;padding:.5em 1em;background:#fcf0f1;color:#1e1e1e;font-size:.875em">'
            . htmlspecialchars(__($message), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . ' <code>' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code></p>';
    }
}
