<?php

declare(strict_types=1);

namespace Framework\Embed\Tests;

use Framework\Embed\Document;
use Framework\Embed\Format;
use Framework\Embed\Renderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixturesTest extends TestCase
{
    public const SPEC = __DIR__ . '/../../spec';

    public static function fixtures(): array
    {
        $cases = [];
        foreach (glob(self::SPEC . '/fixtures/*.json') as $file) {
            $cases[basename($file, '.json')] = [basename($file, '.json')];
        }
        ksort($cases);

        return $cases;
    }

    #[DataProvider('fixtures')]
    public function test_fixture_renders_the_expected_html(string $name): void
    {
        $json = file_get_contents(self::SPEC . "/fixtures/{$name}.json");
        $expected = file_get_contents(self::SPEC . "/fixtures/{$name}.html");
        $this->assertSame($expected, Renderer::renderJson($json));
    }

    #[DataProvider('fixtures')]
    public function test_fixture_renders_the_same_from_objects(string $name): void
    {
        $json = file_get_contents(self::SPEC . "/fixtures/{$name}.json");
        $expected = file_get_contents(self::SPEC . "/fixtures/{$name}.html");
        $this->assertSame($expected, Renderer::render(json_decode($json, false)));
    }

    public function test_number_formatting_matches_the_shared_cases(): void
    {
        $cases = json_decode(file_get_contents(self::SPEC . '/format-cases.json'), true);
        $this->assertGreaterThan(600, count($cases));
        $failures = [];
        foreach ($cases as $case) {
            $actual = match ($case['format']) {
                'number' => Format::number($case['value']),
                'decimal' => Format::number($case['value'], true),
                'p' => Format::pValue($case['value']),
                'percent' => Format::percent($case['value']),
            };
            if ($actual !== $case['expected']) {
                $failures[] = [$case, $actual];
            }
        }
        $this->assertSame([], $failures);
    }

    public function test_decode_rejects_other_documents(): void
    {
        $this->assertNull(Document::decode('{"schema":"framework.artifact/v1"}'));
        $this->assertNull(Document::decode('[1]'));
        $this->assertNull(Document::decode('not json'));
        $this->assertSame('', Renderer::renderJson('null'));
    }
}
