<?php

declare(strict_types=1);

namespace Drupal\framework_embed;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Render\Markup;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Framework\Embed\Client;
use Framework\Embed\Document;
use Framework\Embed\EmbedException;
use Framework\Embed\Http\Response;
use Framework\Embed\Renderer;
use Framework\Embed\UrlMatcher;
use Psr\Log\LoggerInterface;

/**
 * Fetches and renders artifacts through the framework-cloud/embed core.
 *
 * Live tier (the text filter and the link formatter): build() renders from
 * the framework_embed cache bin and never waits on the network. Without a
 * copy it renders a link and queues a fetch, which runs after the response is
 * sent (destruct()) or from cron (the framework_embed_refresh queue). An
 * expired copy still renders while it is refreshed. A 404 drops the copy.
 *
 * Stored tier (the Framework artifact field) uses request() when an editor
 * saves, and renderStored() when the entity is viewed.
 */
class Embedder implements TrustedCallbackInterface, DestructableInterface {

  use StringTranslationTrait;

  /**
   * Cache tag on every embed, invalidated by cron and the settings form.
   */
  public const TAG = 'framework_embed';

  public const QUEUE = 'framework_embed_refresh';

  /**
   * Seconds an expired copy may still render while it is refreshed.
   */
  public const STALE_TTL = 604800;

  /**
   * Seconds between fetch attempts for one URL.
   */
  public const RETRY_TTL = 300;

  /**
   * Seconds a 404 is remembered before the URL is tried again.
   */
  public const GONE_TTL = 3600;

  /**
   * URLs queued during this request, fetched after the response is sent.
   *
   * @var array<string, true>
   */
  private array $deferred = [];

  /**
   * @param callable $fetcher
   *   Takes an embed API URL and returns a Framework\Embed\Http\Response.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private $fetcher,
    private readonly CacheBackendInterface $cache,
    private readonly QueueFactory $queueFactory,
    private readonly CacheTagsInvalidatorInterface $invalidator,
    private readonly AccountInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['build'];
  }

  /**
   * The allowed hosts: framework.pub and the administrator's extra hosts.
   *
   * @return list<string>
   */
  public function hosts(): array {
    $extra = $this->configFactory->get('framework_embed.settings')->get('hosts');

    return array_values(array_unique(array_merge(UrlMatcher::DEFAULT_HOSTS, is_array($extra) ? array_filter($extra, 'is_string') : [])));
  }

  public function matcher(): UrlMatcher {
    return new UrlMatcher($this->hosts());
  }

  /**
   * Whether a URL is a Framework artifact on an allowed host.
   */
  public function isArtifactUrl(string $url): bool {
    return $this->matcher()->match($url) !== NULL;
  }

  /**
   * Requests one embed document now, bypassing every cache.
   *
   * @return array{doc: ?array, body: string, ttl: int}
   *   doc is NULL when the server answers 404 or 410.
   *
   * @throws \Framework\Embed\EmbedException
   */
  public function request(string $url): array {
    $fetcher = $this->fetcher;
    $response = NULL;
    $capture = static function (string $requestUrl) use ($fetcher, &$response) {
      $answer = $fetcher($requestUrl);
      if (is_array($answer)) {
        $answer = new Response((int) ($answer['status'] ?? 0), (array) ($answer['headers'] ?? []), (string) ($answer['body'] ?? ''));
      }
      return $response = $answer;
    };
    $doc = (new Client($this->hosts(), $capture))->resolve($url);
    $maxTtl = max(0, (int) ($this->configFactory->get('framework_embed.settings')->get('max_ttl') ?? 3600));

    return [
      'doc' => $doc,
      'body' => $response instanceof Response ? $response->body : '',
      'ttl' => $response instanceof Response ? min($maxTtl, Client::ttl($response)) : 0,
    ];
  }

  /**
   * The URL that answers with the newest version of the artifact at $url.
   */
  public function newestUrl(string $url): ?string {
    $match = $this->matcher()->match($url);
    if ($match === NULL) {
      return NULL;
    }
    if ($match->type === 'path') {
      return "{$match->origin}/@{$match->handle}/{$match->project}/{$match->slug}";
    }

    // A short link always opens the newest version
    if ($match->type === 'go') {
        return $match->url;
    }

    return $match->origin . ($match->type === 'embed' ? '/api/embed/v1/artifacts/' : '/d/') . $match->key;
  }

  /**
   * A placeholder render array that builds the live embed per request.
   */
  public function lazy(string $url): array {
    return [
      '#lazy_builder' => ['framework_embed.embedder:build', [$url]],
      '#create_placeholder' => TRUE,
    ];
  }

  /**
   * Cache tag for one URL, invalidated when its copy changes.
   */
  public static function urlTag(string $url): string {
    return 'framework_embed_url:' . substr(sha1(trim($url)), 0, 16);
  }

  /**
   * Lazy builder for the live tier.
   */
  public function build(string $url): array {
    $url = trim($url);
    $cache = [
      'tags' => [self::TAG, self::urlTag($url), 'config:framework_embed.settings'],
      'contexts' => ['user.permissions'],
      'max-age' => 0,
    ];
    if (!$this->isArtifactUrl($url)) {
      return ['link' => $this->link($url)] + $this->notice($this->t('This URL is not a Framework artifact on an allowed host. Administrators add Framework Cloud servers under Configuration → Content authoring → Framework embeds.'), $url, $cache);
    }

    $entry = $this->cache->get($this->cid('live', $url));
    if ($entry !== FALSE && is_array($entry->data) && isset($entry->data['body'], $entry->data['expires'])) {
      $doc = Document::decode((string) $entry->data['body']);
      if ($doc !== NULL) {
        $remaining = (int) $entry->data['expires'] - $this->time->getCurrentTime();
        if ($remaining <= 0) {
          $this->schedule($url);
        }
        $cache['max-age'] = max(0, $remaining);
        $cache['contexts'] = [];

        return $this->figure($doc, $cache);
      }
    }

    if ($this->cache->get($this->cid('gone', $url)) !== FALSE) {
      return ['link' => $this->link($url)] + $this->notice($this->t('This Framework artifact is private, password-protected, or deleted. Visitors see a link.'), $url, $cache);
    }
    $this->schedule($url);

    return ['link' => $this->link($url)] + $this->notice($this->t('This artifact is being fetched in the background. Visitors see a link until the fetch completes.'), $url, $cache);
  }

  /**
   * Queues a fetch, at most once per RETRY_TTL for a URL.
   */
  public function schedule(string $url): void {
    $pending = $this->cid('pending', $url);
    if ($this->cache->get($pending) !== FALSE) {
      return;
    }
    $this->cache->set($pending, TRUE, $this->time->getRequestTime() + self::RETRY_TTL);
    $this->queueFactory->get(self::QUEUE)->createItem(['url' => $url]);
    $this->deferred[$url] = TRUE;
  }

  /**
   * Fetches queued URLs after the response has been sent.
   *
   * The queue item stays; the worker skips URLs that are fresh by then.
   */
  public function destruct(): void {
    foreach (array_keys($this->deferred) as $url) {
      $this->refresh($url);
    }
    $this->deferred = [];
  }

  /**
   * Whether a URL has a copy that is still within its max-age.
   */
  public function isFresh(string $url): bool {
    $entry = $this->cache->get($this->cid('live', $url));

    return $entry !== FALSE && is_array($entry->data) && (int) ($entry->data['expires'] ?? 0) > $this->time->getCurrentTime();
  }

  /**
   * Fetches a URL and updates its cached copy.
   *
   * @return string
   *   fresh, uncacheable, gone, or error. On error the old copy stays.
   */
  public function refresh(string $url): string {
    $url = trim($url);
    try {
      $answer = $this->request($url);
    }
    catch (EmbedException $e) {
      $this->logger->warning('Framework embed fetch failed for @url: @message', ['@url' => $url, '@message' => $e->getMessage()]);
      return 'error';
    }
    $now = $this->time->getCurrentTime();
    $this->cache->delete($this->cid('pending', $url));
    $this->invalidator->invalidateTags([self::urlTag($url)]);

    if ($answer['doc'] === NULL) {
      $this->cache->delete($this->cid('live', $url));
      $this->cache->set($this->cid('gone', $url), TRUE, $now + self::GONE_TTL);
      return 'gone';
    }
    $this->cache->delete($this->cid('gone', $url));
    if ($answer['ttl'] <= 0) {
      $this->cache->delete($this->cid('live', $url));
      return 'uncacheable';
    }
    $this->cache->set(
      $this->cid('live', $url),
      ['body' => $answer['body'], 'expires' => $now + $answer['ttl']],
      $now + $answer['ttl'] + self::STALE_TTL,
      [self::TAG],
    );

    return 'fresh';
  }

  /**
   * Render array for a stored embed document.
   */
  public function renderStored(?string $json, string $url, bool $removed = FALSE): array {
    $doc = $json !== NULL && $json !== '' ? Document::decode($json) : NULL;
    if ($doc !== NULL) {
      return $this->figure($doc, []);
    }
    $build = ['link' => $this->link($url)];
    if ($removed) {
      $build += $this->notice($this->t('This artifact is no longer available on the Framework Cloud server, so its stored copy was removed. Visitors see a link.'), $url, ['contexts' => ['user.permissions']]);
    }

    return $build;
  }

  /**
   * A figure with its libraries. Image artifacts point at the image URL in
   * the document; no file is copied.
   */
  private function figure(array $doc, array $cache): array {
    $html = Renderer::render($doc);
    $libraries = ['framework_embed/framework_embed'];
    // The script draws charts, opens variable tips on hover, and shows a snippet's buttons
    if (str_contains($html, 'data-fw-embed-chart') || str_contains($html, 'fw-embed__term') || str_contains($html, 'fw-embed__snippet')) {
      $libraries[] = 'framework_embed/chart';
    }

    return [
      // Markup objects skip Xss::filterAdmin, which would remove the chart's
      // JSON <script>. Renderer escapes all artifact text itself.
      '#markup' => Markup::create($html),
      '#attached' => ['library' => $libraries],
    ] + ($cache === [] ? [] : ['#cache' => $cache]);
  }

  private function cid(string $kind, string $url): string {
    return "{$kind}:" . sha1(trim($url));
  }

  private function link(string $url): array {
    if (!UrlHelper::isValid($url, TRUE) || !preg_match('#^https?://#i', $url)) {
      return [];
    }

    return [
      '#markup' => Markup::create('<p><a href="' . Html::escape($url) . '">' . Html::escape($url) . '</a></p>'),
    ];
  }

  /**
   * A notice for users allowed to see why an embed is missing.
   */
  private function notice($message, string $url, array $cache): array {
    $build = ['#cache' => $cache];
    if ($this->currentUser->hasPermission('view framework embed notices')) {
      $build['notice'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['framework-embed-notice', 'messages', 'messages--warning'], 'role' => 'note'],
        'text' => ['#plain_text' => (string) $message . ' '],
        'url' => ['#type' => 'html_tag', '#tag' => 'code', '#value' => Html::escape($url)],
      ];
    }

    return $build;
  }

  /**
   * Invalidates every embed. Used by the settings form and cron.
   */
  public function invalidateAll(): void {
    Cache::invalidateTags([self::TAG]);
  }

}
