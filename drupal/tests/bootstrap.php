<?php

/**
 * @file
 * Standalone bootstrap for the Unit tests, which need no Drupal: the core
 * package from ../php plus this module's src/. Kernel tests run under
 * Drupal's own PHPUnit configuration instead.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/php/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
  foreach (['Drupal\\framework_embed\\' => '/src/', 'Drupal\\Tests\\framework_embed\\' => '/tests/src/'] as $prefix => $dir) {
    if (str_starts_with($class, $prefix)) {
      $file = dirname(__DIR__) . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
      if (is_file($file)) {
        require $file;
      }
    }
  }
});
