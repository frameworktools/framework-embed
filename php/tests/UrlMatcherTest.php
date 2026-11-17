<?php

declare(strict_types=1);

namespace Framework\Embed\Tests;

use Framework\Embed\UrlMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlMatcherTest extends TestCase
{
    public function test_framework_pub_is_allowed_over_https_by_default(): void
    {
        $matcher = new UrlMatcher();
        $this->assertSame('https://framework.pub', $matcher->allowedOrigin('https://framework.pub/d/abc'));
        $this->assertSame('https://framework.pub', $matcher->allowedOrigin('HTTPS://Framework.PUB:443/d/abc'));
    }

    public static function rejected(): array
    {
        $urls = [
            'http://framework.pub/d/abc', 'https://framework.pub.evil.com/d/abc', 'https://evil.com/d/abc', 'https://sub.framework.pub/d/abc',
            'https://framework.pub@evil.com/d/abc', 'https://evil.com@framework.pub/d/abc', 'https://user:pass@framework.pub/d/abc',
            'https://framework.pub:8443/d/abc', 'https://framework.pub./d/abc', 'https://framework%2epub/d/abc', 'https://framework.pub\\@evil.com/d/abc',
            'https://127.0.0.1/d/abc', 'https://2130706433/d/abc', 'https://0x7f.0.0.1/d/abc', 'https://[::1]/d/abc', 'https://framework.pub /d/abc',
            'ftp://framework.pub/d/abc', '//framework.pub/d/abc', 'javascript://framework.pub/%0aalert(1)', 'http://localhost/d/abc', '',
            "https://framework.pub\n.evil.com/d/abc", 
        ];

        return array_combine($urls, array_map(fn ($url) => [$url], $urls));
    }

    #[DataProvider('rejected')]
    public function test_rejects(string $url): void
    {
        $matcher = new UrlMatcher();
        $this->assertFalse($matcher->isAllowed($url));
        $this->assertNull($matcher->requestUrl($url));
    }

    public function test_http_localhost_only_when_listed_as_an_origin(): void
    {
        $matcher = new UrlMatcher(['framework.pub', 'http://localhost:8000']);
        $this->assertSame('http://localhost:8000', $matcher->allowedOrigin('http://localhost:8000/d/abc'));
        $this->assertFalse($matcher->isAllowed('http://localhost/d/abc'));
        $this->assertFalse($matcher->isAllowed('https://localhost:8000/d/abc'));
        $this->assertFalse((new UrlMatcher(['http://127.0.0.1:8000']))->isAllowed('http://127.0.0.1:8000/d/abc'));
    }

    public static function matching(): array
    {
        return [
            ['https://framework.pub/d/aB3dE5fG7hJ9kL1m', 'key', ['key' => 'aB3dE5fG7hJ9kL1m', 'version' => null]],
            ['https://framework.pub/d/aB3dE5fG7hJ9kL1m/versions/3?x=1#y', 'key', ['key' => 'aB3dE5fG7hJ9kL1m', 'version' => 3]],
            ['https://framework.pub/@ada/admissions/model', 'path', ['handle' => 'ada', 'project' => 'admissions', 'slug' => 'model', 'version' => null]],
            ['https://framework.pub/@ada/admissions/model/versions/12/', 'path', ['handle' => 'ada', 'slug' => 'model', 'version' => 12]],
            ['https://framework.pub/api/embed/v1/artifacts/abc/versions/2', 'embed', ['key' => 'abc', 'version' => 2]],
        ];
    }

    #[DataProvider('matching')]
    public function test_matches(string $url, string $type, array $fields): void
    {
        $match = (new UrlMatcher())->match($url);
        $this->assertNotNull($match);
        $this->assertSame($type, $match->type);
        foreach ($fields as $field => $value) {
            $this->assertSame($value, $match->{$field});
        }
    }

    public function test_does_not_match_other_paths(): void
    {
        $matcher = new UrlMatcher();
        foreach (['https://framework.pub/', 'https://framework.pub/d/', 'https://framework.pub/d/a/b', 'https://framework.pub/@ada/../x/y',
            'https://framework.pub/d/abc/versions/0', 'https://framework.pub/r/abc', 'https://framework.pub#@evil.com/d/abc'] as $url) {
            $this->assertNull($matcher->match($url), $url);
        }
    }

    public function test_request_urls(): void
    {
        $matcher = new UrlMatcher();
        $this->assertSame('https://framework.pub/api/embed/v1/resolve?url=https%3A%2F%2Fframework.pub%2F%40ada%2Fadmissions%2Fmodel%2Fversions%2F2',
            $matcher->requestUrl('https://framework.pub/@ada/admissions/model/versions/2?utm=1'));
        $this->assertSame('https://framework.pub/api/embed/v1/artifacts/abc', $matcher->requestUrl('https://framework.pub/api/embed/v1/artifacts/abc'));
    }
}
