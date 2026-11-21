<?php

declare(strict_types=1);

use Framework\Embed\EmbedException;
use FrameworkEmbedTesting\FixtureServer;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../spec/fixtures';

    private Framework_Embed_Plugin $plugin;

    private array $requested = [];

    protected function setUp(): void
    {
        WP::reset();
        $this->plugin = Framework_Embed_Plugin::instance();
        $this->serve();
    }

    private function serve(array $gone = [], array $down = [], array $bumped = []): void
    {
        WP::$filters['framework_embed_fetcher'] = [fn () => new FixtureServer(self::FIXTURES, $gone, $down, $bumped, function (string $url) {
            $this->requested[] = $url;
        })];
    }

    private function figure(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . "/{$name}.html");
    }

    public function test_hosts_add_the_option_to_framework_pub(): void
    {
        WP::$options['framework_embed_hosts'] = ['framework.example.org', 42, 'framework.pub'];

        $this->assertSame(['framework.pub', 'framework.example.org'], $this->plugin->hosts());
    }

    public function test_request_returns_the_raw_body_and_ttl(): void
    {
        $answer = $this->plugin->request('https://framework.pub/d/minimal-model');

        $this->assertSame('minimalmodel0000', $answer['doc']['key']);
        $this->assertSame(file_get_contents(self::FIXTURES . '/minimal-model.json'), $answer['body']);
        $this->assertSame(300, $answer['ttl']);
        $this->assertSame(['https://framework.pub/api/embed/v1/resolve?url=https%3A%2F%2Fframework.pub%2Fd%2Fminimal-model'], $this->requested);
    }

    public function test_request_ttl_is_capped_by_the_filter(): void
    {
        add_filter('framework_embed_max_ttl', fn () => 60);

        $this->assertSame(60, $this->plugin->request('https://framework.pub/d/minimal-model/versions/1')['ttl']);
    }

    public function test_live_first_view_is_a_link_and_schedules_one_fetch(): void
    {
        $url = 'https://framework.pub/d/minimal-model';

        $html = Framework_Embed_Live::render($url);
        Framework_Embed_Live::render($url);

        $this->assertSame('<p><a href="https://framework.pub/d/minimal-model">https://framework.pub/d/minimal-model</a></p>', $html);
        $this->assertSame([], $this->requested, 'Rendering never fetches.');
        $this->assertSame([['framework_embed_refresh', [$url]]], WP::$scheduled, 'One job per URL until it runs.');
    }

    public function test_refresh_fills_the_cache_and_views_render_the_figure(): void
    {
        $url = 'https://framework.pub/d/python-altair';

        $this->assertSame('fresh', Framework_Embed_Live::refresh($url));
        $entry = WP::$transients[Framework_Embed_Live::key('live', $url)];
        $this->assertSame(300 + WEEK_IN_SECONDS, $entry[1], 'Kept past max-age for stale-while-revalidate.');
        $this->assertSame($this->figure('python-altair'), Framework_Embed_Live::render($url));
        $this->assertSame(['style:framework-embed', 'script:framework-embed'], WP::$enqueued);
        $this->assertSame([], WP::$scheduled);
    }

    public function test_a_figure_with_variable_tips_enqueues_the_script(): void
    {
        $url = 'https://framework.pub/d/r-lm-variables';

        $this->assertSame('fresh', Framework_Embed_Live::refresh($url));
        $this->assertStringContainsString('fw-embed__term', Framework_Embed_Live::render($url));
        $this->assertSame(['style:framework-embed', 'script:framework-embed'], WP::$enqueued);
    }

    public function test_a_code_snippet_enqueues_the_script(): void
    {
        $url = 'https://framework.pub/d/r-snippet';

        $this->assertSame('fresh', Framework_Embed_Live::refresh($url));
        $this->assertStringContainsString('fw-embed__snippet', Framework_Embed_Live::render($url));
        $this->assertSame(['style:framework-embed', 'script:framework-embed'], WP::$enqueued);
    }

    public function test_expired_copy_renders_and_schedules_a_refresh(): void
    {
        $url = 'https://framework.pub/d/minimal-test';
        Framework_Embed_Live::refresh($url);
        $key = Framework_Embed_Live::key('live', $url);
        WP::$transients[$key][0]['expires'] = time() - 1;

        $this->assertSame($this->figure('minimal-test'), Framework_Embed_Live::render($url));
        $this->assertSame([['framework_embed_refresh', [$url]]], WP::$scheduled);
    }

    public function test_refresh_404_drops_the_copy(): void
    {
        $url = 'https://framework.pub/d/minimal-test';
        Framework_Embed_Live::refresh($url);
        $this->serve(gone: ['minimal-test']);

        $this->assertSame('gone', Framework_Embed_Live::refresh($url));
        $this->assertArrayNotHasKey(Framework_Embed_Live::key('live', $url), WP::$transients);
        $this->assertStringStartsWith('<p><a href=', Framework_Embed_Live::render($url));
        WP::$canEdit = true;
        $this->assertStringContainsString('private, password-protected, or deleted', Framework_Embed_Live::render($url));
    }

    public function test_refresh_failure_keeps_the_stale_copy(): void
    {
        $url = 'https://framework.pub/d/minimal-test';
        Framework_Embed_Live::refresh($url);
        $this->serve(down: ['minimal-test']);

        $this->assertSame('error', Framework_Embed_Live::refresh($url));
        $this->assertSame($this->figure('minimal-test'), Framework_Embed_Live::render($url));
    }

    public function test_disallowed_hosts_are_never_fetched(): void
    {
        $this->assertFalse($this->plugin->handle_embed([], [], 'https://evil.example/d/abc'));
        $this->assertFalse($this->plugin->handle_embed([], [], 'https://127.0.0.1/d/abc'));
        $this->assertFalse($this->plugin->handle_embed([], [], 'http://framework.pub/d/abc'));
        $this->assertStringContainsString('<a href="https://evil.example/d/abc">', $this->plugin->render_shortcode(['url' => 'https://evil.example/d/abc']));
        $this->assertSame([], WP::$scheduled);
        $this->expectException(EmbedException::class);
        $this->plugin->request('https://evil.example/d/abc');
    }

    public function test_stored_block_renders_from_its_copy_without_requests(): void
    {
        $body = (string) file_get_contents(self::FIXTURES . '/r-ggplot.json');

        $html = $this->plugin->render_block(['url' => 'https://framework.pub/d/r-ggplot', 'document' => $body]);

        $this->assertSame('<div class="wp-block-framework-artifact">' . $this->figure('r-ggplot') . '</div>', $html);
        $this->assertStringContainsString('src="https://framework.pub/api/embed/v1/artifacts/rggplot000000000/versions/1/image"', $html);
        $this->assertSame([], $this->requested);
        $this->assertSame([], WP::$scheduled);
    }

    public function test_removed_and_legacy_blocks(): void
    {
        $this->assertSame('<div class="wp-block-framework-artifact"><p><a href="https://framework.pub/d/x">https://framework.pub/d/x</a></p></div>', $this->plugin->render_block(['url' => 'https://framework.pub/d/x', 'document' => '', 'removed' => true]));
        $this->assertSame('', $this->plugin->render_block(['url' => '  ']));
        $this->plugin->render_block(['url' => 'https://framework.pub/d/minimal-test']);
        $this->assertSame([['framework_embed_refresh', ['https://framework.pub/d/minimal-test']]], WP::$scheduled, 'A block without a copy uses the live tier.');
    }

    public function test_fetch_for_editor_pins_the_version_url(): void
    {
        $result = Framework_Embed_Stored::fetch_for_editor('https://framework.pub/d/minimal-test');

        $this->assertSame('https://framework.pub/api/embed/v1/artifacts/minimaltest00000/versions/1', $result['embedUrl']);
        $this->assertSame(file_get_contents(self::FIXTURES . '/minimal-test.json'), $result['document']);
        $this->assertSame(rtrim($this->figure('minimal-test'), "\n") . "\n", $result['html']);
    }

    public function test_fetch_for_editor_newest_version(): void
    {
        $this->serve(bumped: ['r-lmer']);
        $pinned = Framework_Embed_Stored::fetch_for_editor('https://framework.pub/d/r-lmer/versions/1');
        $newest = Framework_Embed_Stored::fetch_for_editor($pinned['embedUrl'], true);

        $this->assertSame([1, 2], [$pinned['version'], $pinned['latestVersion']]);
        $this->assertSame(2, $newest['version']);
        $this->assertSame('https://framework.pub/api/embed/v1/artifacts/rlmer00000000000/versions/2', $newest['embedUrl']);
        $this->assertSame('https://framework.pub/api/embed/v1/artifacts/rlmer00000000000', end($this->requested));
    }

    public function test_fetch_for_editor_errors(): void
    {
        $this->serve(gone: ['page'], down: ['minimal-test']);

        $this->assertSame(404, Framework_Embed_Stored::fetch_for_editor('https://framework.pub/d/page')->data['status']);
        $this->assertSame(502, Framework_Embed_Stored::fetch_for_editor('https://framework.pub/d/minimal-test')->data['status']);
        $this->assertSame(400, Framework_Embed_Stored::fetch_for_editor('https://evil.example/d/page')->data['status']);
    }

    public function test_newest_url(): void
    {
        $this->assertSame('https://framework.pub/api/embed/v1/artifacts/abc', Framework_Embed_Stored::newest_url('https://framework.pub/api/embed/v1/artifacts/abc/versions/3'));
        $this->assertSame('https://framework.pub/d/abc', Framework_Embed_Stored::newest_url('https://framework.pub/d/abc/versions/3'));
        $this->assertSame('https://framework.pub/go/aqxh', Framework_Embed_Stored::newest_url('https://framework.pub/go/aqxh'));
        $this->assertSame('https://framework.pub/@ada/p/s', Framework_Embed_Stored::newest_url('https://framework.pub/@ada/p/s/versions/3/'));
        $this->assertNull(Framework_Embed_Stored::newest_url('https://evil.example/d/abc'));
    }

    public function test_fetch_uses_the_safe_http_api_without_redirects(): void
    {
        WP::$remote = ['response' => ['code' => 200], 'headers' => ['Cache-Control' => 'max-age=5', 'X-Multi' => ['a', 'b']], 'body' => '{}'];

        $response = $this->plugin->fetch('https://framework.pub/api/embed/v1/artifacts/abc');

        $this->assertSame(200, $response->status);
        $this->assertSame('max-age=5', $response->header('cache-control'));
        $this->assertSame('a, b', $response->header('x-multi'));
        $args = WP::$requests[0]['args'];
        $this->assertSame(0, $args['redirection']);
        $this->assertSame(5, $args['timeout']);
        $this->assertSame([], $args['cookies']);
        $this->assertFalse(WP::$requests[0]['external'], 'framework.pub gets no exemption from the safe-URL checks');
    }

    public function test_fetch_exempts_only_hosts_the_administrator_listed(): void
    {
        WP::$options['framework_embed_hosts'] = ['http://framework.internal:8800'];
        WP::$remote = ['response' => ['code' => 404], 'headers' => [], 'body' => ''];

        $this->plugin->fetch('http://framework.internal:8800/api/embed/v1/artifacts/abc');

        $this->assertTrue(WP::$requests[0]['external']);
        $this->assertContains(8800, WP::$requests[0]['ports']);
        $this->assertSame([], WP::$filters['http_request_host_is_external'], 'The exemption is removed after the request');
    }

    public function test_fetch_turns_wp_errors_into_embed_exceptions(): void
    {
        WP::$remote = new WP_Error('http_request_failed', 'timed out');

        $this->expectException(EmbedException::class);
        $this->plugin->fetch('https://framework.pub/api/embed/v1/artifacts/abc');
    }
}
