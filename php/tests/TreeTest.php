<?php

declare(strict_types=1);

namespace Framework\Embed\Tests;

use DOMDocument;
use DOMXPath;
use Framework\Embed\Renderer;
use PHPUnit\Framework\TestCase;

final class TreeTest extends TestCase
{
    private static function load(string $name): array
    {
        return json_decode(file_get_contents(FixturesTest::SPEC . "/fixtures/{$name}.json"), true);
    }

    private static function tree(array $children, array $extra = []): array
    {
        return ['kind' => 'tree', 'title' => 'T', 'data' => ['schema' => 'framework.artifact/v1', 'kind' => 'tree', 'tree' => ['root' => 'p', 'children' => $children], ...$extra]];
    }

    private static function xpath(array $doc): DOMXPath
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . Renderer::render($doc) . '</body>');

        return new DOMXPath($dom);
    }

    /** @return list<string> */
    private static function texts(DOMXPath $xpath, string $class): array
    {
        $texts = [];
        foreach ($xpath->query('//*[contains(concat(" ", @class, " "), " ' . $class . ' ")]') as $node) {
            $texts[] = $node->textContent;
        }

        return $texts;
    }

    public function test_rows_follow_fw_structure(): void
    {
        $xpath = self::xpath(self::load('r-tree'));
        $this->assertSame(['my-project/', '├── data/', '│   ├── private/', '│   └── raw/', '├── outputs/', '│   ├── table.csv',
            '│   └── *.rds', '├── R/', '│   └── clean.R', '├── renv/', '└── README.md'], self::texts($xpath, 'fw-embed__tree-name'));
        $this->assertSame(3, $xpath->query('//details[@open]')->length);
        $this->assertSame(['# private · 3 files', '# ignored · data/raw/ · 12 files', '# ignored · *.rds · 4 files', '# 1,520 files'], self::texts($xpath, 'fw-embed__tree-badge'));
        $this->assertSame(['.gitignore line 4', '.gitignore line 7'], array_map(fn ($a) => $a->value, iterator_to_array($xpath->query('//span[@title]/@title'))));
    }

    public function test_counts_and_rule_sources(): void
    {
        $xpath = self::xpath(self::load('tree-subfolder'));
        $this->assertSame(['# ignored · fits/ · 10,000+ files', '# private · ignored · scratch/ · 1 file', '# ignored · *.log · 11 files'], self::texts($xpath, 'fw-embed__tree-badge'));
        $this->assertSame(['analysis/'], self::texts($xpath, 'fw-embed__tree-crumb'));
    }

    public function test_display_flags_leave_out_notes_and_ignored_paths(): void
    {
        $xpath = self::xpath(self::load('tree-display-hidden'));
        $this->assertSame([], self::texts($xpath, 'fw-embed__tree-note'));
        $this->assertSame(['# private · 3 files', '# 1,520 files'], self::texts($xpath, 'fw-embed__tree-badge'));
        $doc = self::tree([['name' => 'secret_private', 'type' => 'dir', 'private' => true, 'ignored' => ['rule' => 'secret_private/'], 'hidden' => 2, 'children' => []]], ['display' => ['ignored' => false]]);
        $this->assertSame(['# private · 2 files'], self::texts(self::xpath($doc), 'fw-embed__tree-badge'));
    }

    public function test_unusable_trees_render_as_page(): void
    {
        $this->assertSame(['p/'], self::texts(self::xpath(self::tree([])), 'fw-embed__tree-name'));
        foreach ([null, [], ['tree' => []], ['tree' => ['children' => []]], ['tree' => ['root' => '', 'children' => []]]] as $data) {
            $this->assertStringContainsString('fw-embed--page', Renderer::render(['kind' => 'tree', 'title' => 'T', 'data' => $data]));
        }
        $this->assertStringContainsString('data-fw-embed-kind="page"', Renderer::render([...self::load('r-tree'), 'kind' => 'tree-v2']));
    }

    public function test_long_trees_start_clipped(): void
    {
        $html = Renderer::render(self::load('tree-long'));
        $this->assertStringContainsString('<div class="fw-embed__tree-panel">' . "\n" . '<div class="fw-embed__tree" data-fw-embed-clip style=', $html);
        $this->assertStringContainsString("</div>\n" . '<button type="button" class="fw-embed__more" hidden aria-expanded="false">Show more</button>' . "\n</div>\n</div>", $html);
        foreach (['tree-long-full', 'r-tree', 'tree-subfolder'] as $name) {
            $html = Renderer::render(self::load($name));
            $this->assertStringNotContainsString('data-fw-embed-clip', $html, $name);
            $this->assertStringNotContainsString('fw-embed__more', $html, $name);
            $this->assertStringContainsString('<div class="fw-embed__tree-panel">' . "\n" . '<div class="fw-embed__tree" style=', $html, $name);
        }
        $files = fn (int $count) => array_map(fn (int $i) => ['name' => "f{$i}", 'type' => 'file'], range(1, $count));
        $this->assertStringNotContainsString('data-fw-embed-clip', Renderer::render(self::tree($files(14))));
        $this->assertStringContainsString('data-fw-embed-clip', Renderer::render(self::tree($files(15))));
        $folder = fn (bool $open) => [['name' => 'd', 'type' => 'dir', 'open' => $open, 'children' => $files(20)]];
        $this->assertStringNotContainsString('data-fw-embed-clip', Renderer::render(self::tree($folder(false))));
        $this->assertStringContainsString('data-fw-embed-clip', Renderer::render(self::tree($folder(true))));
    }

    public function test_folder_chevrons_widen_the_name_column(): void
    {
        $this->assertStringContainsString('--fw-embed-tree-names: 17ch', Renderer::render(self::tree([['name' => 'abcdefghij', 'type' => 'dir', 'open' => true, 'children' => [['name' => 'f', 'type' => 'file']]]])));
        $this->assertStringContainsString('--fw-embed-tree-names: 15ch', Renderer::render(self::tree([['name' => 'abcdefghij', 'type' => 'dir', 'open' => true, 'children' => []]])));
    }

    public function test_depth_and_row_limits(): void
    {
        $node = ['name' => 'f', 'type' => 'file'];
        for ($i = 0; $i < 40; $i++) {
            $node = ['name' => "d{$i}", 'type' => 'dir', 'open' => true, 'children' => [$node]];
        }
        $this->assertSame(31, substr_count(Renderer::render(self::tree([$node])), '<details'));
        $many = array_map(fn (int $i) => ['name' => "f{$i}", 'type' => 'file'], range(0, 5999));
        $this->assertSame(5000, substr_count(Renderer::render(self::tree($many)), 'fw-embed__tree-row--file'));
    }
}
