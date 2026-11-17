<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Tests;

use Framework\Embed\EmbedException;
use FrameworkCloud\StatamicEmbed\HttpFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

final class HttpFetcherTest extends TestCase
{
    public function test_maps_the_answer_to_a_response(): void
    {
        Http::fake(['framework.pub/*' => Http::response('{"a":1}', 200, ['Cache-Control' => 'public, max-age=300', 'X-Multi' => ['a', 'b']])]);

        $response = (new HttpFetcher(app(Factory::class)))('https://framework.pub/api/embed/v1/artifacts/abc');

        $this->assertSame(200, $response->status);
        $this->assertSame('{"a":1}', $response->body);
        $this->assertSame('public, max-age=300', $response->header('cache-control'));
        $this->assertSame('a, b', $response->header('x-multi'));
        Http::assertSent(fn ($request) => $request->url() === 'https://framework.pub/api/embed/v1/artifacts/abc' && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_redirects_are_returned_not_followed(): void
    {
        Http::fake(['framework.pub/*' => Http::response('', 302, ['Location' => 'https://evil.example/'])]);

        $this->assertSame(302, (new HttpFetcher(app(Factory::class)))('https://framework.pub/api/embed/v1/artifacts/abc')->status);
        Http::assertSentCount(1);
    }

    public function test_connection_errors_become_embed_exceptions(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(EmbedException::class);
        (new HttpFetcher(app(Factory::class)))('https://framework.pub/api/embed/v1/artifacts/abc');
    }
}
