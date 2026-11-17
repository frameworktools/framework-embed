<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Tests;

use FrameworkEmbedTesting\FixtureServer;
use FrameworkCloud\StatamicEmbed\ServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    public const FIXTURES = __DIR__ . '/../../spec/fixtures';

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        // New sites use the runtime parser; Statamic's fallback is the legacy regex parser.
        $app['config']->set('statamic.antlers.version', 'runtime');
        $app['config']->set('app.debug', true);
    }

    /** URLs the fake fetcher was asked for. */
    protected array $requests = [];

    /** FixtureServer lists: gone, down, bump. */
    protected array $server = ['gone' => [], 'down' => [], 'bump' => []];

    /** Binds testing/FixtureServer.php as the fetcher. */
    protected function fakeFetcher(): void
    {
        require_once __DIR__ . '/../../testing/FixtureServer.php';
        $this->app->bind('framework-embed.fetcher', fn () => new FixtureServer(
            self::FIXTURES,
            $this->server['gone'],
            $this->server['down'],
            $this->server['bump'],
            function (string $url) {
                $this->requests[] = $url;
            },
        ));
        $this->app->forgetScopedInstances();
    }

    /** Sets a FixtureServer list for the next request. */
    protected function serve(string $list, array $names): void
    {
        $this->server[$list] = $names;
        $this->app->forgetScopedInstances();
    }

    /** Runs what happens after a response: dispatchAfterResponse jobs. Then starts a new request scope. */
    protected function afterResponse(): void
    {
        $this->app->terminate();
        // terminate() keeps its callbacks; drop them so the next call runs only new ones.
        (fn () => $this->terminatingCallbacks = [])->call($this->app);
        $this->app->forgetScopedInstances();
    }

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . "/{$name}.html");
    }
}
