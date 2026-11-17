<?php

declare(strict_types=1);

namespace Drupal\framework_embed_test;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Framework\Embed\Http\Response;
use FrameworkEmbedTesting\FixtureServer;

/**
 * Test only. Answers embed requests with testing/FixtureServer.php.
 *
 * State keys: framework_embed_test.requests counts requests;
 * framework_embed_test.gone, .down, and .bump hold the FixtureServer lists.
 */
final class FixtureFetcher {

  public function __construct(private readonly StateInterface $state) {}

  public function __invoke(string $url): Response {
    require_once dirname(self::directory(), 2) . '/testing/FixtureServer.php';
    $server = new FixtureServer(
      self::directory(),
      (array) $this->state->get('framework_embed_test.gone', []),
      (array) $this->state->get('framework_embed_test.down', []),
      (array) $this->state->get('framework_embed_test.bump', []),
      fn () => $this->state->set('framework_embed_test.requests', (int) $this->state->get('framework_embed_test.requests', 0) + 1),
    );

    return $server($url);
  }

  /**
   * The fixtures directory: $settings['framework_embed_fixtures'], else the repository's spec/fixtures.
   */
  public static function directory(): string {
    return (string) (Settings::get('framework_embed_fixtures') ?? getenv('FRAMEWORK_EMBED_FIXTURES') ?: dirname(__DIR__, 5) . '/spec/fixtures');
  }

}
