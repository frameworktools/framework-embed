<?php

declare(strict_types=1);

namespace Framework\Embed\Tests;

use Framework\Embed\ArrayCache;
use Framework\Embed\Assets;
use Framework\Embed\Client;
use Framework\Embed\EmbedException;
use Framework\Embed\Http\Response;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private array $requests = [];

    private function fetcher(array $responses): callable
    {
        return function (string $url) use (&$responses): Response {
            $this->requests[] = $url;

            return array_shift($responses);
        };
    }

    private static function glm(): string
    {
        return file_get_contents(FixturesTest::SPEC . '/fixtures/r-glm-logit.json');
    }

    public function test_resolves_through_the_resolve_endpoint(): void
    {
        $client = new Client(fetcher: $this->fetcher([new Response(200, ['Cache-Control' => 'public, max-age=300'], self::glm())]));
        $doc = $client->resolve('https://framework.pub/@ada/admissions/r-glm-logit/versions/1');
        $this->assertSame('model', $doc['kind']);
        $this->assertSame(['https://framework.pub/api/embed/v1/resolve?url=https%3A%2F%2Fframework.pub%2F%40ada%2Fadmissions%2Fr-glm-logit%2Fversions%2F1'], $this->requests);
    }

    public function test_never_requests_hosts_that_are_not_allowed(): void
    {
        $client = new Client(fetcher: $this->fetcher([]));
        foreach (['https://evil.com/d/abc', 'https://framework.pub@evil.com/d/abc', 'http://framework.pub/d/abc', 'https://10.0.0.1/d/abc', 'http://localhost/d/abc'] as $url) {
            try {
                $client->resolve($url);
                $this->fail("{$url} was allowed");
            } catch (EmbedException $e) {
                $this->assertSame(EmbedException::NOT_ALLOWED, $e->reason);
            }
        }
        $this->assertSame([], $this->requests);
    }

    public function test_allows_localhost_when_configured(): void
    {
        $client = new Client(['http://localhost:8000'], $this->fetcher([new Response(200, [], self::glm())]));
        $this->assertNotNull($client->fetch('http://localhost:8000/api/embed/v1/artifacts/abc/versions/1'));
        $this->assertSame(['http://localhost:8000/api/embed/v1/artifacts/abc/versions/1'], $this->requests);
    }

    public function test_fetch_takes_only_embed_urls(): void
    {
        $this->expectException(EmbedException::class);
        (new Client(fetcher: $this->fetcher([])))->fetch('https://framework.pub/d/abc');
    }

    public function test_caches_for_max_age_less_age(): void
    {
        $now = 1000;
        $cache = new ArrayCache(function () use (&$now) { return $now; });
        $client = new Client(fetcher: $this->fetcher([
            new Response(200, ['cache-control' => 'public, max-age=300', 'age' => '100'], self::glm()),
            new Response(200, ['cache-control' => 'public, max-age=300'], self::glm()),
        ]), cache: $cache);
        $client->resolve('https://framework.pub/d/abc');
        $now += 199;
        $client->resolve('https://framework.pub/d/abc');
        $this->assertCount(1, $this->requests);
        $now += 2;
        $client->resolve('https://framework.pub/d/abc');
        $this->assertCount(2, $this->requests);
    }

    public function test_does_not_cache_without_permission(): void
    {
        $cache = new ArrayCache();
        $client = new Client(fetcher: $this->fetcher([
            new Response(200, ['cache-control' => 'no-store'], self::glm()),
            new Response(200, [], self::glm()),
            new Response(200, ['cache-control' => 'private, max-age=60'], self::glm()),
        ]), cache: $cache);
        foreach (range(1, 3) as $i) {
            $client->resolve('https://framework.pub/d/abc');
        }
        $this->assertCount(3, $this->requests);
    }

    public function test_caps_the_ttl(): void
    {
        $this->assertSame(3600, Client::ttl(new Response(200, ['cache-control' => 'public, max-age=3600'])));
        $this->assertSame(0, Client::ttl(new Response(200, ['cache-control' => 'no-cache'])));
        $this->assertSame(0, Client::ttl(new Response(200, ['cache-control' => 'max-age=10', 'age' => '20'])));
    }

    public function test_404_drops_the_cached_copy_and_means_gone(): void
    {
        $now = 1000;
        $cache = new ArrayCache(function () use (&$now) { return $now; });
        $client = new Client(fetcher: $this->fetcher([
            new Response(200, ['cache-control' => 'max-age=60'], self::glm()),
            new Response(404, ['cache-control' => 'no-store'], '{"message":"Not found."}'),
            new Response(410, [], ''),
        ]), cache: $cache);
        $this->assertNotNull($client->resolve('https://framework.pub/d/abc'));
        $now += 61;
        $this->assertNull($client->resolve('https://framework.pub/d/abc'));
        $this->assertNull($cache->get('framework-embed:' . sha1('https://framework.pub/api/embed/v1/resolve?url=https%3A%2F%2Fframework.pub%2Fd%2Fabc')));
        $this->assertNull($client->resolve('https://framework.pub/d/abc'));
    }

    public function test_errors_and_invalid_answers_throw(): void
    {
        $client = new Client(fetcher: $this->fetcher([new Response(500), new Response(302, ['location' => 'https://evil.com/']), new Response(200, [], '{"schema":"other"}')]));
        foreach (['http', 'http', 'invalid'] as $reason) {
            try {
                $client->resolve('https://framework.pub/d/abc');
                $this->fail('no exception');
            } catch (EmbedException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }

    public function test_array_responses_and_render(): void
    {
        $client = new Client(fetcher: fn () => ['status' => 200, 'headers' => [], 'body' => self::glm()]);
        $this->assertSame(file_get_contents(FixturesTest::SPEC . '/fixtures/r-glm-logit.html'), $client->render('https://framework.pub/d/rglmlogit0000000'));
    }

    public function test_assets_ship_with_the_package(): void
    {
        $this->assertFileExists(Assets::css());
        $this->assertFileExists(Assets::script());
        $this->assertFileExists(Assets::module());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', Assets::version());
    }
}
