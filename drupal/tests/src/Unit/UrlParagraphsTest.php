<?php

declare(strict_types=1);

namespace Drupal\Tests\framework_embed\Unit;

use Drupal\framework_embed\UrlParagraphs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @group framework_embed
 */
#[Group('framework_embed')]
final class UrlParagraphsTest extends TestCase {

  public static function paragraphs(): array {
    return [
      'bare URL' => ['https://framework.pub/d/abc', 'https://framework.pub/d/abc'],
      'padded' => ["  \n https://framework.pub/d/abc&nbsp;", 'https://framework.pub/d/abc'],
      'link with matching text' => ['<a href="https://framework.pub/d/abc">https://framework.pub/d/abc</a>', 'https://framework.pub/d/abc'],
      'link with single quotes and attributes' => ["<a class=\"x\" href='https://framework.pub/d/abc?a=1&amp;b=2' rel=\"nofollow\">https://framework.pub/d/abc?a=1&amp;b=2</a>", 'https://framework.pub/d/abc?a=1&b=2'],
      'entity in bare URL' => ['https://framework.pub/d/abc?a=1&amp;b=2', 'https://framework.pub/d/abc?a=1&b=2'],
      'link with other text' => ['<a href="https://framework.pub/d/abc">Our model</a>', NULL],
      'URL in a sentence' => ['See https://framework.pub/d/abc for details', NULL],
      'two URLs' => ['https://framework.pub/d/a https://framework.pub/d/b', NULL],
      'formatted URL' => ['<strong>https://framework.pub/d/abc</strong>', NULL],
      'javascript' => ['javascript:alert(1)', NULL],
      'empty' => ['', NULL],
    ];
  }

  #[DataProvider('paragraphs')]
  public function testUrl(string $inner, ?string $expected): void {
    $this->assertSame($expected, UrlParagraphs::url($inner));
  }

  public function testReplaceOnlyTouchesAcceptedParagraphs(): void {
    $html = "<p>Intro https://framework.pub/d/x</p>\n<p class=\"lead\">https://framework.pub/d/abc</p>\n<p><a href=\"https://elsewhere.example/d/abc\">https://elsewhere.example/d/abc</a></p>\n<P>https://framework.pub/d/def</P>";
    $seen = [];
    $out = UrlParagraphs::replace($html, function (string $url) use (&$seen): ?string {
      $seen[] = $url;
      return str_starts_with($url, 'https://framework.pub/') ? '[' . $url . ']' : NULL;
    });

    $this->assertSame(['https://framework.pub/d/abc', 'https://elsewhere.example/d/abc', 'https://framework.pub/d/def'], $seen);
    $this->assertSame("<p>Intro https://framework.pub/d/x</p>\n[https://framework.pub/d/abc]\n<p><a href=\"https://elsewhere.example/d/abc\">https://elsewhere.example/d/abc</a></p>\n[https://framework.pub/d/def]", $out);
  }

  public function testNestedOrUnclosedParagraphsAreLeftAlone(): void {
    $html = '<p>https://framework.pub/d/abc<p>text</p>';
    $this->assertSame($html, UrlParagraphs::replace($html, fn () => 'X'));
    $this->assertSame('<pre>https://framework.pub/d/abc</pre>', UrlParagraphs::replace('<pre>https://framework.pub/d/abc</pre>', fn () => 'X'));
    $this->assertSame('<param>x</param>', UrlParagraphs::replace('<param>x</param>', fn () => 'X'));
  }

}
