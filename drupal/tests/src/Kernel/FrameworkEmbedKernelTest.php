<?php

declare(strict_types=1);

namespace Drupal\Tests\framework_embed\Kernel;

use Drupal\Core\Render\RenderContext;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\framework_embed\Embedder;
use Drupal\framework_embed\HostList;
use Drupal\framework_embed_test\FixtureFetcher;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Live tier (filter, link formatter), stored tier (field), and takedown,
 * with fixture fetches.
 *
 * @group framework_embed
 */
#[Group('framework_embed')]
#[RunTestsInSeparateProcesses]
final class FrameworkEmbedKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'filter',
    'field',
    'link',
    'entity_test',
    'framework_embed',
    'framework_embed_test',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['system', 'filter', 'framework_embed']);
    FilterFormat::create([
      'format' => 'embeds',
      'name' => 'Embeds',
      'filters' => [
        'filter_html' => ['status' => TRUE, 'weight' => -10, 'settings' => ['allowed_html' => '<p class> <a href> <strong>']],
        'framework_embed' => ['status' => TRUE, 'weight' => 10],
      ],
    ])->save();
    // User 1 would bypass permission checks; start as an anonymous visitor.
    $this->setUpCurrentUser([], ['access content']);
  }

  private function fixture(string $name): string {
    return (string) file_get_contents(FixtureFetcher::directory() . "/{$name}.html");
  }

  private function renderBuild(array &$build): string {
    $renderer = $this->container->get('renderer');

    return (string) $renderer->executeInRenderContext(new RenderContext(), function () use ($renderer, &$build) {
      return $renderer->renderRoot($build);
    });
  }

  private function filtered(string $text): array {
    $build = ['#type' => 'processed_text', '#text' => $text, '#format' => 'embeds'];
    $html = $this->renderBuild($build);

    return [$html, $build];
  }

  private function requests(): int {
    return (int) $this->container->get('state')->get('framework_embed_test.requests', 0);
  }

  private function embedder(): Embedder {
    return $this->container->get('framework_embed.embedder');
  }

  /**
   * Runs what normally happens after the response is sent.
   */
  private function afterResponse(): void {
    $this->embedder()->destruct();
  }

  private function expire(string $url): void {
    $bin = $this->container->get('cache.framework_embed');
    $item = $bin->get('live:' . sha1($url));
    $this->assertNotFalse($item);
    $bin->set('live:' . sha1($url), ['expires' => 1] + $item->data, $item->expire, $item->tags);
  }

  public function testLiveFirstViewIsALinkThenTheFigure(): void {
    $text = "<p>Before.</p>\n<p>https://framework.pub/d/minimal-model</p>\n<p><a href=\"https://framework.pub/@ada/admissions/python-altair\">https://framework.pub/@ada/admissions/python-altair</a></p>\n<p>After https://framework.pub/d/r-tibble.</p>";
    [$first, $build] = $this->filtered($text);

    $this->assertSame(0, $this->requests(), 'Rendering never fetches.');
    $this->assertStringContainsString('<p><a href="https://framework.pub/d/minimal-model">https://framework.pub/d/minimal-model</a></p>', $first);
    $this->assertStringNotContainsString('class="fw-embed ', $first);
    $this->assertSame(2, $this->container->get('queue')->get(Embedder::QUEUE)->numberOfItems());
    $this->assertContains(Embedder::urlTag('https://framework.pub/d/minimal-model'), $build['#cache']['tags']);

    $this->afterResponse();
    $this->assertSame(2, $this->requests());
    [$html, $build] = $this->filtered($text);

    $this->assertStringContainsString($this->fixture('minimal-model'), $html);
    $this->assertStringContainsString($this->fixture('python-altair'), $html, 'The chart JSON <script> survives rendering.');
    $this->assertStringContainsString('<p>After https://framework.pub/d/r-tibble.</p>', $html);
    $this->assertContains('framework_embed/chart', $build['#attached']['library']);
    $this->assertContains('config:framework_embed.settings', $build['#cache']['tags']);
    $this->assertGreaterThan(290, $build['#cache']['max-age'], 'A fresh figure carries its remaining max-age.');
    $this->assertLessThanOrEqual(300, $build['#cache']['max-age']);
    $this->assertSame(2, $this->requests(), 'Later views render from the cache.');
  }

  public function testALongTreeAttachesTheScript(): void {
    $text = "<p>https://framework.pub/d/tree-long</p>";
    $this->filtered($text);
    $this->afterResponse();
    [$html, $build] = $this->filtered($text);

    $this->assertStringContainsString('data-fw-embed-clip', $html);
    $this->assertContains('framework_embed/chart', $build['#attached']['library']);
  }

  public function testEveryFixtureMatchesTheSpec(): void {
    $text = '';
    $names = [];
    foreach (glob(FixtureFetcher::directory() . '/*.json') as $file) {
      $names[] = $name = basename($file, '.json');
      $text .= "<p>https://framework.pub/d/{$name}</p>\n";
    }
    $this->filtered($text);
    $this->afterResponse();
    [$html] = $this->filtered($text);

    foreach ($names as $name) {
      $this->assertStringContainsString($this->fixture($name), $html, $name);
    }
  }

  public function testCronRunsQueuedFetches(): void {
    $this->filtered('<p>https://framework.pub/d/minimal-test</p>');
    // A request that ended before destruct() leaves only the queue item.
    $this->container->get('cron')->run();

    [$html] = $this->filtered('<p>https://framework.pub/d/minimal-test</p>');
    $this->assertStringContainsString($this->fixture('minimal-test'), $html);
  }

  public function testStaleCopyRendersWhileItRefreshes(): void {
    $this->filtered('<p>https://framework.pub/d/minimal-test</p>');
    $this->afterResponse();
    $this->expire('https://framework.pub/d/minimal-test');

    [$stale] = $this->filtered('<p>https://framework.pub/d/minimal-test</p>');
    $this->assertStringContainsString($this->fixture('minimal-test'), $stale);
    $this->assertSame(1, $this->requests());
    $this->afterResponse();
    $this->assertSame(2, $this->requests(), 'Refreshed in the background.');
  }

  public function testRefresh404DropsTheCopy(): void {
    $this->filtered('<p>https://framework.pub/d/minimal-test</p>');
    $this->afterResponse();
    $this->container->get('state')->set('framework_embed_test.gone', ['minimal-test']);
    $this->expire('https://framework.pub/d/minimal-test');
    $this->filtered('<p>https://framework.pub/d/minimal-test</p>');
    $this->afterResponse();

    [$html] = $this->filtered('<p>https://framework.pub/d/minimal-test</p>');
    $this->assertStringNotContainsString('class="fw-embed ', $html);
    $this->assertStringContainsString('<a href="https://framework.pub/d/minimal-test">', $html);
  }

  public function testFailuresForVisitorsAndEditors(): void {
    $this->container->get('state')->set('framework_embed_test.down', ['stata-stcox']);
    $text = "<p>https://framework.pub/d/gone-artifact</p>\n<p>https://framework.pub/d/stata-stcox</p>\n<p>https://evil.example/d/minimal-model</p>";
    $this->filtered($text);
    $this->afterResponse();
    [$visitor] = $this->filtered($text);

    $this->assertStringContainsString('<a href="https://framework.pub/d/gone-artifact">', $visitor);
    $this->assertStringContainsString('<a href="https://framework.pub/d/stata-stcox">', $visitor);
    $this->assertStringContainsString('<p>https://evil.example/d/minimal-model</p>', $visitor, 'Disallowed hosts are left as text.');
    $this->assertStringNotContainsString('framework-embed-notice', $visitor);

    $this->setUpCurrentUser([], ['view framework embed notices']);
    [$editor] = $this->filtered($text);
    $this->assertStringContainsString('private, password-protected, or deleted', $editor);
    $this->assertStringContainsString('being fetched in the background', $editor);
  }

  public function testExtraHostsFromConfiguration(): void {
    $this->filtered('<p>http://localhost:8000/d/minimal-test</p>');
    $this->assertSame(0, $this->container->get('queue')->get(Embedder::QUEUE)->numberOfItems());

    $this->config('framework_embed.settings')->set('hosts', [HostList::normalize('http://localhost:8000/')])->save();
    $this->filtered('<p>http://localhost:8000/d/minimal-test</p>');
    $this->afterResponse();
    [$after] = $this->filtered('<p>http://localhost:8000/d/minimal-test</p>');
    $this->assertStringContainsString($this->fixture('minimal-test'), $after);
  }

  public function testLinkFieldFormatterIsLive(): void {
    FieldStorageConfig::create(['field_name' => 'field_link', 'entity_type' => 'entity_test', 'type' => 'link'])->save();
    FieldConfig::create(['field_name' => 'field_link', 'entity_type' => 'entity_test', 'bundle' => 'entity_test'])->save();
    $entity = EntityTest::create(['field_link' => [['uri' => 'https://framework.pub/d/stata-regress'], ['uri' => 'internal:/node/1']]]);
    $entity->save();

    $build = $entity->get('field_link')->view(['type' => 'framework_embed']);
    $first = $this->renderBuild($build);
    $this->afterResponse();
    $build = $entity->get('field_link')->view(['type' => 'framework_embed']);
    $html = $this->renderBuild($build);

    $this->assertStringContainsString('<a href="https://framework.pub/d/stata-regress">', $first);
    $this->assertStringContainsString($this->fixture('stata-regress'), $html);
    $this->assertStringNotContainsString('/node/1', $html);
  }

  private function createArtifactField(): void {
    FieldStorageConfig::create(['field_name' => 'field_artifact', 'entity_type' => 'entity_test', 'type' => 'framework_artifact', 'cardinality' => -1])->save();
    FieldConfig::create(['field_name' => 'field_artifact', 'entity_type' => 'entity_test', 'bundle' => 'entity_test'])->save();
  }

  private function stored(string $name): array {
    $json = (string) file_get_contents(FixtureFetcher::directory() . "/{$name}.json");

    return ['url' => "https://framework.pub/d/{$name}", 'embed_url' => json_decode($json, TRUE)['embed_url'], 'document' => $json, 'fetched_at' => 1763300000];
  }

  public function testStoredFieldRendersFromItsCopy(): void {
    $this->createArtifactField();
    $entity = EntityTest::create(['field_artifact' => [$this->stored('r-glm-logit'), $this->stored('r-ggplot')]]);
    $entity->save();
    $entity = EntityTest::load($entity->id());

    $build = $entity->get('field_artifact')->view(['type' => 'framework_artifact_stored']);
    $html = $this->renderBuild($build);

    $this->assertStringContainsString($this->fixture('r-glm-logit'), $html);
    $this->assertStringContainsString($this->fixture('r-ggplot'), $html);
    $this->assertStringContainsString('src="https://framework.pub/api/embed/v1/artifacts/rggplot000000000/versions/1/image"', $html, 'Images point at the stored image URL.');
    $this->assertSame(0, $this->requests(), 'Stored copies never fetch.');
    $this->assertSame(0, $this->container->get('queue')->get(Embedder::QUEUE)->numberOfItems());
  }

  public function testTakedownRemovesOnlyDefiniteGone(): void {
    $this->createArtifactField();
    $entity = EntityTest::create(['field_artifact' => [$this->stored('minimal-model'), $this->stored('stata-stcox'), $this->stored('r-lmer')]]);
    $entity->save();
    $state = $this->container->get('state');
    $state->set('framework_embed_test.gone', ['minimal-model']);
    $state->set('framework_embed_test.down', ['stata-stcox']);

    $counts = $this->container->get('framework_embed.takedown')->run();
    $entity = EntityTest::load($entity->id());

    $this->assertSame(['checked' => 3, 'removed' => 1], $counts);
    $this->assertNull($entity->get('field_artifact')[0]->get('document')->getValue());
    $this->assertTrue((bool) $entity->get('field_artifact')[0]->get('removed')->getValue());
    $this->assertNotEmpty($entity->get('field_artifact')[1]->get('document')->getValue(), 'A failed request keeps the copy.');
    $this->assertNotEmpty($entity->get('field_artifact')[2]->get('document')->getValue());

    $build = $entity->get('field_artifact')->view(['type' => 'framework_artifact_stored']);
    $html = $this->renderBuild($build);
    $this->assertStringContainsString('<p><a href="https://framework.pub/d/minimal-model">https://framework.pub/d/minimal-model</a></p>', $html);
    $this->assertStringContainsString($this->fixture('stata-stcox'), $html);
  }

  public function testTakedownCanBeSwitchedOff(): void {
    $this->createArtifactField();
    EntityTest::create(['field_artifact' => [$this->stored('minimal-model')]])->save();
    $this->container->get('state')->set('framework_embed_test.gone', ['minimal-model']);
    $this->config('framework_embed.settings')->set('takedown', FALSE)->save();

    $this->assertSame(['checked' => 0, 'removed' => 0], $this->container->get('framework_embed.takedown')->run());
    $this->assertSame(0, $this->requests());
  }

  public function testNewestUrl(): void {
    $embedder = $this->embedder();
    $this->assertSame('https://framework.pub/api/embed/v1/artifacts/abc', $embedder->newestUrl('https://framework.pub/api/embed/v1/artifacts/abc/versions/2'));
    $this->assertSame('https://framework.pub/@ada/p/s', $embedder->newestUrl('https://framework.pub/@ada/p/s/versions/2'));

    $this->container->get('state')->set('framework_embed_test.bump', ['r-lmer']);
    $answer = $embedder->request((string) $embedder->newestUrl('https://framework.pub/api/embed/v1/artifacts/rlmer00000000000/versions/1'));
    $this->assertSame(2, $answer['doc']['version']);
  }

}
