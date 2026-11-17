<?php

declare(strict_types=1);

namespace FrameworkCloud\StatamicEmbed\Tests;

use FrameworkCloud\StatamicEmbed\HostList;
use FrameworkCloud\StatamicEmbed\UrlParagraphs;
use PHPUnit\Framework\TestCase as PlainTestCase;

final class UrlParagraphsTest extends PlainTestCase
{
    public function test_url(): void
    {
        $this->assertSame('https://framework.pub/d/abc', UrlParagraphs::url(' https://framework.pub/d/abc '));
        $this->assertSame('https://framework.pub/d/a?b=1&c=2', UrlParagraphs::url('<a href="https://framework.pub/d/a?b=1&amp;c=2" target="_blank">https://framework.pub/d/a?b=1&amp;c=2</a>'));
        $this->assertNull(UrlParagraphs::url('<a href="https://framework.pub/d/abc">The model</a>'));
        $this->assertNull(UrlParagraphs::url('See https://framework.pub/d/abc'));
        $this->assertNull(UrlParagraphs::url('<em>https://framework.pub/d/abc</em>'));
    }

    public function test_replace(): void
    {
        $html = "<p>x</p><p>https://framework.pub/d/abc</p><p>https://framework.pub/d/keep</p>";
        $out = UrlParagraphs::replace($html, fn (string $url) => str_ends_with($url, 'abc') ? '<figure></figure>' : null);

        $this->assertSame('<p>x</p><figure></figure><p>https://framework.pub/d/keep</p>', $out);
    }

    public function test_host_list(): void
    {
        [$hosts, $invalid] = HostList::resolve(['Framework.Example.org', 'https://framework.pub', 'http://localhost:8000/', '127.0.0.1', 'user@x.example', 42]);

        $this->assertSame(['framework.pub', 'framework.example.org', 'http://localhost:8000'], $hosts);
        $this->assertSame(['127.0.0.1', 'user@x.example'], $invalid);
    }
}
