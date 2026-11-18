<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Tests;

use FrameworkCloud\StatamicEmbed\Embedder;
use Statamic\Facades\Parse;
use Statamic\Facades\User;

/** Live tier: the url tag, the modifier, and the styles and scripts tags. */
final class EmbedTest extends TestCase
{
    private function antlers(string $template, array $data = []): string
    {
        // A template without a newline makes Antlers write \r\n line endings
        // (StringUtilities::detectNewLineStyle); layout files always have one.
        $html = (string) Parse::template($template . "\n", $data, [], true);

        return str_ends_with($html, "\n") ? substr($html, 0, -1) : $html;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeFetcher();
    }

    public function test_first_view_is_a_link_then_the_figure(): void
    {
        $template = '{{ framework:artifact url="https://framework.pub/d/minimal-model" }}';

        $this->assertSame('<p><a href="https://framework.pub/d/minimal-model">https://framework.pub/d/minimal-model</a></p>', $this->antlers($template));
        $this->assertSame([], $this->requests, 'Rendering never fetches.');
        $this->afterResponse();
        $this->assertCount(1, $this->requests);

        $this->assertSame($this->fixture('minimal-model'), $this->antlers($template));
        $link = ['link' => 'https://framework.pub/@ada/admissions/r-glm-logit/versions/2'];
        $this->assertStringStartsWith('<p><a href=', $this->antlers('{{ framework:artifact :url="link" }}', $link));
        $this->afterResponse();
        $this->assertSame($this->fixture('r-glm-logit'), $this->antlers('{{ framework:artifact :url="link" }}', $link));
    }

    public function test_one_fetch_per_url_until_it_runs(): void
    {
        $this->antlers('{{ framework:artifact url="https://framework.pub/d/minimal-test" }}');
        $this->app->forgetScopedInstances();
        $this->antlers('{{ framework:artifact url="https://framework.pub/d/minimal-test" }}');
        $this->afterResponse();

        $this->assertCount(1, $this->requests);
    }

    public function test_every_fixture_matches_the_spec(): void
    {
        $files = glob(self::FIXTURES . '/*.json');
        foreach ($files as $file) {
            app(Embedder::class)->render('https://framework.pub/d/' . basename($file, '.json'));
        }
        $this->afterResponse();
        foreach ($files as $file) {
            $name = basename($file, '.json');
            $this->assertSame($this->fixture($name), app(Embedder::class)->render("https://framework.pub/d/{$name}"), $name);
        }
    }

    public function test_the_modifier_converts_url_paragraphs_only(): void
    {
        $content = "<p>Before.</p>\n<p>https://framework.pub/d/minimal-test</p>\n<p><a href=\"https://framework.pub/d/python-altair\">https://framework.pub/d/python-altair</a></p>\n<p>See https://framework.pub/d/r-tibble.</p>\n<p>https://evil.example/d/minimal-model</p>";
        $this->antlers('{{ content | framework_embeds }}', ['content' => $content]);
        $this->afterResponse();

        $html = $this->antlers('{{ content | framework_embeds }}', ['content' => $content]);

        $this->assertStringContainsString("<p>Before.</p>\n" . $this->fixture('minimal-test'), $html);
        $this->assertStringContainsString($this->fixture('python-altair'), $html);
        $this->assertStringContainsString('<p>See https://framework.pub/d/r-tibble.</p>', $html);
        $this->assertStringContainsString('<p>https://evil.example/d/minimal-model</p>', $html);
        $this->assertCount(2, $this->requests, 'Only allowed artifact URLs are fetched.');
    }

    public function test_expired_copy_renders_and_refreshes_in_the_background(): void
    {
        $template = '{{ framework:artifact url="https://framework.pub/d/minimal-test" }}';
        $this->antlers($template);
        $this->afterResponse();
        $this->travel(301)->seconds();

        $this->assertSame($this->fixture('minimal-test'), $this->antlers($template), 'Stale copy still renders.');
        $this->assertCount(1, $this->requests);
        $this->afterResponse();
        $this->assertCount(2, $this->requests, 'Refreshed after the response.');
    }

    public function test_refresh_404_drops_the_copy(): void
    {
        $template = '{{ framework:artifact url="https://framework.pub/d/minimal-test" }}';
        $this->antlers($template);
        $this->afterResponse();
        $this->travel(301)->seconds();
        $this->serve('gone', ['minimal-test']);
        $this->antlers($template);
        $this->afterResponse();

        $this->assertSame('<p><a href="https://framework.pub/d/minimal-test">https://framework.pub/d/minimal-test</a></p>', $this->antlers($template));
    }

    public function test_failures_for_visitors(): void
    {
        $this->serve('down', ['stata-stcox']);
        $this->antlers('{{ framework:artifact url="https://framework.pub/d/gone-artifact" }}{{ framework:artifact url="https://framework.pub/d/stata-stcox" }}');
        $this->afterResponse();

        $this->assertSame('<p><a href="https://framework.pub/d/gone-artifact">https://framework.pub/d/gone-artifact</a></p>', $this->antlers('{{ framework:artifact url="https://framework.pub/d/gone-artifact" }}'));
        $this->assertSame('<p><a href="https://framework.pub/d/stata-stcox">https://framework.pub/d/stata-stcox</a></p>', $this->antlers('{{ framework:artifact url="https://framework.pub/d/stata-stcox" }}'));
        $this->assertSame('<p><a href="https://evil.example/d/x">https://evil.example/d/x</a></p>', $this->antlers('{{ framework:artifact url="https://evil.example/d/x" }}'));
        $this->assertSame('', $this->antlers('{{ framework:artifact url="javascript:alert(1)" }}'));
        $this->assertSame('', $this->antlers('{{ framework:artifact }}'));
    }

    public function test_control_panel_users_see_notices(): void
    {
        $user = User::make()->email('editor@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $this->assertStringContainsString('being fetched in the background', $this->antlers('{{ framework:artifact url="https://framework.pub/d/gone-artifact" }}'));
        $this->afterResponse();
        $this->assertStringContainsString('private, password-protected, or deleted', $this->antlers('{{ framework:artifact url="https://framework.pub/d/gone-artifact" }}'));
    }

    public function test_extra_hosts_from_config(): void
    {
        config(['framework-embed.hosts' => ['framework.example.org', 'http://localhost:8000/', '10.0.0.1', 'https://x.example/path']]);
        $this->app->forgetScopedInstances();
        $embedder = app(Embedder::class);

        $this->assertTrue($embedder->isArtifactUrl('https://framework.example.org/d/abc'));
        $this->assertTrue($embedder->isArtifactUrl('http://localhost:8000/d/abc'));
        $this->assertFalse($embedder->isArtifactUrl('https://10.0.0.1/d/abc'));
        $this->assertFalse($embedder->isArtifactUrl('http://framework.example.org/d/abc'));
        $this->assertSame($this->fixture('minimal-test'), $embedder->renderStored($embedder->fetchForStorage('https://framework.example.org/d/minimal-test')));
    }

    public function test_queue_mode_pushes_a_job(): void
    {
        config(['framework-embed.refresh' => 'queue']);
        \Illuminate\Support\Facades\Queue::fake();
        $this->app->forgetScopedInstances();

        $this->antlers('{{ framework:artifact url="https://framework.pub/d/minimal-test" }}');

        \Illuminate\Support\Facades\Queue::assertPushed(\FrameworkCloud\StatamicEmbed\Jobs\RefreshEmbed::class, fn ($job) => $job->url === 'https://framework.pub/d/minimal-test');
    }

    public function test_styles_and_scripts_tags(): void
    {
        $this->assertMatchesRegularExpression('#^<link rel="stylesheet" href="http://localhost/vendor/statamic-embed/framework-embed\.css\?v=[0-9a-f]{12}">$#', $this->antlers('{{ framework:styles }}'));
        $this->assertMatchesRegularExpression('#^<script src="http://localhost/vendor/statamic-embed/framework-embed\.iife\.js\?v=[0-9a-f]{12}" defer></script>$#', $this->antlers('{{ framework:scripts }}'));

        $this->antlers('{{ framework:artifact url="https://framework.pub/d/minimal-test" }}{{ framework:artifact url="https://framework.pub/d/python-altair" }}');
        $this->afterResponse();
        $this->assertSame($this->fixture('minimal-test'), $this->antlers('{{ framework:artifact url="https://framework.pub/d/minimal-test" }}{{ framework:scripts only_if_charts="true" }}'));
        $this->assertStringContainsString('framework-embed.iife.js', $this->antlers('{{ framework:artifact url="https://framework.pub/d/python-altair" }}{{ framework:scripts only_if_charts="true" }}'));

        // Variable tips open on hover through the same script
        $this->app->forgetScopedInstances();
        $this->antlers('{{ framework:artifact url="https://framework.pub/d/r-lm-variables" }}');
        $this->afterResponse();
        $this->assertStringContainsString('framework-embed.iife.js', $this->antlers('{{ framework:artifact url="https://framework.pub/d/r-lm-variables" }}{{ framework:scripts only_if_charts="true" }}'));

        // So do a code snippet's copy and output buttons
        $this->app->forgetScopedInstances();
        $this->antlers('{{ framework:artifact url="https://framework.pub/d/r-snippet" }}');
        $this->afterResponse();
        $this->assertStringContainsString('framework-embed.iife.js', $this->antlers('{{ framework:artifact url="https://framework.pub/d/r-snippet" }}{{ framework:scripts only_if_charts="true" }}'));
    }

    public function test_assets_are_publishable(): void
    {
        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(null, 'framework-embed');

        $this->assertNotEmpty($paths);
        $this->assertFileExists(array_key_first($paths) . '/framework-embed.css');
        $this->assertStringEndsWith('vendor/statamic-embed/', str_replace('\\', '/', reset($paths)));
    }
}
