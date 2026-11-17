<?php

declare(strict_types=1);

namespace Framework\Embed\Tests;

use DOMDocument;
use DOMXPath;
use Framework\Embed\Json;
use Framework\Embed\Renderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private static function html(string $name): string
    {
        return Renderer::renderJson(file_get_contents(FixturesTest::SPEC . "/fixtures/{$name}.json"));
    }

    public static function hostile(): array
    {
        return ['model' => ['hostile-model'], 'page' => ['hostile-page'], 'image' => ['hostile-image'], 'vega' => ['hostile-vega']];
    }

    #[DataProvider('hostile')]
    public function test_hostile_strings_are_escaped(string $name): void
    {
        $html = self::html($name);
        $this->assertDoesNotMatchRegularExpression('/<script>|<img src=x|<b>|javascript:|data:text/i', $html);

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        $xpath = new DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//script[not(@type="application/json")] | //b | //iframe | //object')->length);
        $this->assertSame(0, $xpath->query('//@*[starts-with(name(), "on")]')->length);
        foreach ($xpath->query('//@href | //@src') as $attribute) {
            $this->assertStringStartsWith('https://', $attribute->value);
        }
    }

    public function test_hostile_text_stays_text(): void
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . self::html('hostile-model') . '</body>');
        $title = (new DOMXPath($dom))->query('//*[@class="fw-embed__title"]')->item(0);
        $this->assertSame('<script>alert(1)</script> & "><img src=x onerror=alert(1)>', $title->textContent);
    }

    public function test_chart_json_cannot_close_its_script(): void
    {
        $html = self::html('hostile-vega');
        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertSame('{"a":"\\u003c/script\\u003e\\u2028\\u0026"}', Json::forScript(['a' => "</script>\xE2\x80\xA8&"]));
    }

    public function test_json_matches_javascript_stringify(): void
    {
        $this->assertSame('{"2":1,"10":2,"b":3,"01":4}', Json::stringify(json_decode('{"b":3,"10":2,"01":4,"2":1}')));
        $this->assertSame('{"a":{},"b":[]}', Json::stringify(json_decode('{"a":{},"b":[]}')));
        $this->assertSame('[1e-7,1e+21,2,-0.5,123456789012345680,0.000001,1.5e-7,100]', Json::stringify(json_decode('[1e-7,1e21,2.0,-0.5,123456789012345678,0.000001,1.5e-7,1e2]')));
        $this->assertSame('"tab\t \"q\" \\\\ \u0001 é"', Json::stringify("tab\t \"q\" \\ \x01 é"));
    }

    public function test_only_http_urls_are_used(): void
    {
        $this->assertSame('https://framework.pub/d/abc', Renderer::safeUrl('https://framework.pub/d/abc'));
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', '//evil.com', '/relative', 'https://a b', 'https://x/"y', 'https://x/\\y', ' https://x', "https://x\n", null, 5] as $bad) {
            $this->assertNull(Renderer::safeUrl($bad), var_export($bad, true));
        }
    }

    public function test_garbage_input_renders_without_errors(): void
    {
        foreach ([[], [1], ['kind' => 'model'], ['kind' => 'model', 'data' => ['tables' => [['columns' => [['name' => 'term']], 'rows' => [1, null, [[]]]]]]],
            ['kind' => 'table', 'data' => ['tables' => 'no']], ['kind' => 'test', 'data' => ['test' => ['method' => 'm', 'statistic' => 'x', 'parameters' => [null, ['name' => 1]]]]],
            ['kind' => 'image', 'image' => ['url' => 'https://framework.pub/i.svg', 'width' => -1]], ['kind' => 'vega-lite', 'spec' => []]] as $doc) {
            $this->assertIsString(Renderer::render($doc));
        }
        $this->assertStringContainsString('fw-embed--page', Renderer::render(['kind' => 'model']));
        $this->assertSame('', Renderer::render([1]));
    }
}
