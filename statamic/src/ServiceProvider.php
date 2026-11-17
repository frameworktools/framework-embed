<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed;

use Framework\Embed\Assets;
use FrameworkCloud\StatamicEmbed\Actions\FetchArtifactsAgain;
use FrameworkCloud\StatamicEmbed\Actions\UseNewestArtifactVersions;
use FrameworkCloud\StatamicEmbed\Commands\Takedown;
use FrameworkCloud\StatamicEmbed\Fieldtypes\FrameworkArtifact;
use FrameworkCloud\StatamicEmbed\Modifiers\FrameworkEmbeds;
use FrameworkCloud\StatamicEmbed\Tags\Framework;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Statamic\Facades\User;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [Framework::class];

    protected $modifiers = [FrameworkEmbeds::class];

    protected $fieldtypes = [FrameworkArtifact::class];

    protected $actions = [FetchArtifactsAgain::class, UseNewestArtifactVersions::class];

    protected $commands = [Takedown::class];

    public function register(): void
    {
        parent::register();

        // Published to public/vendor/statamic-embed by `php artisan vendor:publish --tag=framework-embed`,
        // and after `php artisan statamic:install`.
        $this->publishables = [Assets::directory() => ''];

        $this->mergeConfigFrom(__DIR__ . '/../config/framework-embed.php', 'framework-embed');

        // The fetcher: any callable that takes an embed API URL and returns a
        // Framework\Embed\Http\Response without following redirects. Rebind it to change transport or to test.
        $this->app->bind('framework-embed.fetcher', fn (Application $app) => new HttpFetcher(
            $app->make(Factory::class),
            (float) $app['config']->get('framework-embed.timeout', 5),
        ));

        $this->app->scoped(Embedder::class, function (Application $app) {
            $config = $app['config'];
            [$hosts, $invalid] = HostList::resolve((array) $config->get('framework-embed.hosts', []));
            if ($invalid !== []) {
                $app['log']->warning('framework-embed: ignoring hosts that are not a host name or origin: ' . implode(', ', $invalid));
            }

            return new Embedder(
                $hosts,
                $app->make('framework-embed.fetcher'),
                $app['cache']->store($config->get('framework-embed.cache_store')),
                static fn (): bool => (bool) User::current()?->can('access cp'),
                $app['log'],
                (int) $config->get('framework-embed.max_ttl', 3600),
                $config->get('framework-embed.refresh') === 'queue' ? 'queue' : 'after_response',
            );
        });
    }

    protected function schedule(Schedule $schedule)
    {
        if (config('framework-embed.takedown', true)) {
            $schedule->command('framework-embed:takedown')->daily();
        }
    }
}
