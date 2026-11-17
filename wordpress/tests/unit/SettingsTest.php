<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        WP::reset();
    }

    public static function hosts(): array
    {
        return [
            'bare host' => ['framework.example.org', 'framework.example.org'],
            'upper case' => ['Framework.Example.ORG', 'framework.example.org'],
            'https origin is the bare host' => ['https://framework.example.org/', 'framework.example.org'],
            'https default port' => ['https://framework.example.org:443', 'framework.example.org'],
            'http origin with port' => ['http://localhost:8000', 'http://localhost:8000'],
            'https origin with port' => ['https://framework.example.org:8443', 'https://framework.example.org:8443'],
            'IPv4' => ['10.0.0.1', null],
            'IPv4 origin' => ['http://127.0.0.1:8000', null],
            'hex IP' => ['0x7f000001', null],
            'IPv6' => ['http://[::1]:8000', null],
            'path' => ['https://framework.example.org/cloud', null],
            'user info' => ['user@framework.example.org', null],
            'trailing dot' => ['framework.example.org.', null],
            'other scheme' => ['ftp://framework.example.org', null],
            'query' => ['https://framework.example.org/?a=1', null],
            'space' => ['framework example.org', null],
            'empty' => ['  ', null],
        ];
    }

    #[DataProvider('hosts')]
    public function test_normalize_host(string $entry, ?string $expected): void
    {
        $this->assertSame($expected, Framework_Embed_Settings::normalize_host($entry));
    }

    public function test_sanitize_keeps_valid_hosts_and_reports_the_rest(): void
    {
        $saved = Framework_Embed_Settings::sanitize_hosts("framework.example.org\r\n\nframework.pub\nFRAMEWORK.example.org\n10.0.0.1, http://localhost:8000");

        $this->assertSame(['framework.example.org', 'http://localhost:8000'], $saved);
        $this->assertCount(1, WP::$errors);
        $this->assertStringContainsString('10.0.0.1', WP::$errors[0]);
    }

    public function test_sanitize_accepts_an_already_saved_array(): void
    {
        $this->assertSame(['framework.example.org'], Framework_Embed_Settings::sanitize_hosts(['framework.example.org', ['nested']]));
        $this->assertSame([], WP::$errors);
    }
}
