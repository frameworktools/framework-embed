<?php

declare(strict_types=1);

namespace Drupal\Tests\framework_embed\Unit;

use Drupal\framework_embed\HostList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @group framework_embed
 */
#[Group('framework_embed')]
final class HostListTest extends TestCase {

  public static function hosts(): array {
    return [
      'bare host' => ['framework.example.org', 'framework.example.org'],
      'upper case' => ['Framework.Example.ORG', 'framework.example.org'],
      'https origin' => ['https://framework.example.org/', 'framework.example.org'],
      'http origin with port' => ['http://localhost:8000', 'http://localhost:8000'],
      'https origin with port' => ['https://framework.example.org:8443', 'https://framework.example.org:8443'],
      'IPv4' => ['192.168.1.10', NULL],
      'IPv4 origin' => ['http://127.0.0.1:8000', NULL],
      'IPv6' => ['http://[::1]:8000', NULL],
      'path' => ['https://framework.example.org/cloud', NULL],
      'user info' => ['admin@framework.example.org', NULL],
      'other scheme' => ['ftp://framework.example.org', NULL],
    ];
  }

  #[DataProvider('hosts')]
  public function testNormalize(string $entry, ?string $expected): void {
    $this->assertSame($expected, HostList::normalize($entry));
  }

  public function testParse(): void {
    [$valid, $invalid] = HostList::parse("framework.example.org\r\nframework.pub\n\n10.0.0.1, http://localhost:8000\nFRAMEWORK.EXAMPLE.ORG");
    $this->assertSame(['framework.example.org', 'http://localhost:8000'], $valid);
    $this->assertSame(['10.0.0.1'], $invalid);
  }

}
