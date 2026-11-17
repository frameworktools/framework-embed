<?php

/**
 * @file
 * End-to-end checks against a site prepared by run.sh.
 *
 * Usage: php check.php <base URL> <spec/fixtures> <one-time login URL> "<drush command>"
 */

declare(strict_types=1);

$base = rtrim($argv[1], '/');
$fixtures = $argv[2];
$login = $argv[3];
$drush = $argv[4];
$jar = tempnam(sys_get_temp_dir(), 'fw-drupal-cookies');
$failures = 0;
$passes = 0;

function http(string $url, ?array $post = NULL, bool $cookies = FALSE, ?array &$headers = NULL): string {
  global $jar;
  $headers = [];
  $handle = curl_init($url);
  curl_setopt_array($handle, [
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_FOLLOWLOCATION => TRUE,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers): int {
      if (str_contains($line, ':')) {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
      }
      return strlen($line);
    },
  ]);
  if ($cookies) {
    curl_setopt_array($handle, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
  }
  if ($post !== NULL) {
    curl_setopt_array($handle, [CURLOPT_POST => TRUE, CURLOPT_POSTFIELDS => http_build_query($post)]);
  }
  $body = (string) curl_exec($handle);
  $headers['status'] = (string) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
  $headers['url'] = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
  curl_close($handle);
  return $body;
}

function check(string $name, bool $ok, string $detail = ''): void {
  global $failures, $passes;
  $ok ? $passes++ : $failures++;
  echo ($ok ? 'ok   ' : 'FAIL ') . $name . (!$ok && $detail !== '' ? "\n     " . substr($detail, 0, 600) : '') . "\n";
}

function fixture(string $name): string {
  global $fixtures;
  return rtrim((string) file_get_contents("{$fixtures}/{$name}.html"), "\n");
}

/**
 * Sets a FixtureServer list (gone, down, bump) in state.
 */
function server(string $list, array $names): void {
  global $drush;
  shell_exec($drush . ' state:set framework_embed_test.' . $list . ' ' . escapeshellarg(json_encode($names)) . ' --input-format=json 2>&1');
}

function requests(): int {
  global $drush;
  return (int) trim((string) shell_exec($drush . ' state:get framework_embed_test.requests 2>/dev/null'));
}

function form_fields(string $html, string $formId): array {
  preg_match('#name="form_build_id" value="([^"]+)"#', $html, $build);
  preg_match('#name="form_token" value="([^"]+)"#', $html, $token);
  return ['form_build_id' => $build[1] ?? '', 'form_token' => $token[1] ?? '', 'form_id' => $formId];
}

const FIGURE = 'class="fw-embed ';

// ---- Live tier: text filter. First view a link; the fetch runs after the response.
$before = requests();
$first = http("{$base}/node/1");
check('filter: first view is a plain link, no figure', str_contains($first, '<a href="https://framework.pub/d/minimal-model">https://framework.pub/d/minimal-model</a>') && !str_contains($first, FIGURE));
$node1 = http("{$base}/node/1", NULL, FALSE, $headers);
check('filter: fetched after the first response; second view renders exactly', str_contains($node1, fixture('minimal-model')) && str_contains($node1, fixture('python-altair')), "requests {$before} -> " . requests());
check('filter: other paragraphs kept', str_contains($node1, '<p>Before the model.</p>') && str_contains($node1, '<p>After.</p>'));
check('library: stylesheet attached', (bool) preg_match('#<link[^>]+framework_embed/assets/framework-embed\.css#', $node1));
check('library: chart script attached, deferred', (bool) preg_match('#<script[^>]+framework_embed/assets/framework-embed\.iife\.js[^>]*defer#', $node1));
$cached = requests();
http("{$base}/node/1", NULL, FALSE, $headers);
check('page cache: later anonymous view is a cache hit, no requests', ($headers['x-drupal-cache'] ?? '') === 'HIT' && requests() === $cached, json_encode($headers));

// ---- Live tier: link field formatter.
check('link formatter: first view is a plain link', str_contains(http("{$base}/node/4"), '<a href="https://framework.pub/d/r-lmer">'));
$node4 = http("{$base}/node/4");
check('link formatter: second view renders exactly', str_contains($node4, fixture('r-lmer')));
check('link formatter: no chart script without a chart', !str_contains($node4, 'framework-embed.iife.js'));

// ---- Live failures, as a visitor.
server('down', ['stata-stcox']);
http("{$base}/node/2");
$missing = http("{$base}/node/2");
check('visitor: 404 renders a plain link', str_contains($missing, '<a href="https://framework.pub/d/gone">') && !str_contains($missing, FIGURE));
check('visitor: network failure renders a plain link', str_contains($missing, '<a href="https://framework.pub/d/down">'));
check('visitor: disallowed host left as text', str_contains($missing, '<p>https://evil.example/d/minimal-model</p>'));
check('visitor: no notices', !str_contains($missing, 'framework-embed-notice'));
server('down', []);

// ---- Settings, as a visitor.
http("{$base}/admin/config/content/framework-embed", NULL, FALSE, $headers);
check('settings: visitors get 403', $headers['status'] === '403', $headers['status']);
check('extra host: not embedded before it is allowed', !str_contains(http("{$base}/node/3"), FIGURE));

// ---- As the administrator.
http($login, NULL, TRUE);
$editor = http("{$base}/node/2", NULL, TRUE);
check('editor: 404 notice', str_contains($editor, 'private, password-protected, or deleted'));

// ---- Stored tier: the Framework artifact field through the node form.
$add = http("{$base}/node/add/article", NULL, TRUE);
check('widget: URL field on the node form', str_contains($add, 'name="field_framework[0][url]"'));
$rejected = http("{$base}/node/add/article", form_fields($add, 'node_article_form') + ['title[0][value]' => 'stored-gone', 'field_framework[0][url]' => 'https://framework.pub/d/gone', 'op' => 'Save'], TRUE);
check('widget: a 404 artifact is a form error', str_contains($rejected, 'private, password-protected, or deleted') && str_contains($rejected, 'name="field_framework[0][url]"'));
$rejected = http("{$base}/node/add/article", form_fields($rejected, 'node_article_form') + ['title[0][value]' => 'stored-evil', 'field_framework[0][url]' => 'https://evil.example/d/minimal-model', 'op' => 'Save'], TRUE);
check('widget: a disallowed host is a form error', str_contains($rejected, 'not a Framework artifact on an allowed host'));

$nodes = [];
foreach (['model' => 'https://framework.pub/d/minimal-model', 'image' => 'https://framework.pub/d/r-ggplot', 'bumped' => 'https://framework.pub/d/r-lmer/versions/1'] as $slug => $url) {
  if ($slug === 'bumped') {
    server('bump', ['r-lmer']);
  }
  $add = http("{$base}/node/add/article", NULL, TRUE);
  http("{$base}/node/add/article", form_fields($add, 'node_article_form') + ['title[0][value]' => "stored-{$slug}", 'field_framework[0][url]' => $url, 'status[value]' => '1', 'op' => 'Save'], TRUE, $headers);
  $nodes[$slug] = $headers['url'];
  check("stored {$slug}: saved and fetched", (bool) preg_match('#/node/\d+$#', $headers['url']), $headers['url']);
}
$views = requests();
check('request counter works (guards the no-request checks)', $views > 0, (string) $views);
check('stored model: renders the fixture HTML exactly', str_contains(http($nodes['model']), fixture('minimal-model')));
$image = http($nodes['image']);
check('stored image: renders exactly, <img> points at the stored image URL', str_contains($image, fixture('r-ggplot')) && str_contains($image, 'src="https://framework.pub/api/embed/v1/artifacts/rggplot000000000/versions/1/image"'));
check('stored: views make no requests', requests() === $views);
server('down', ['minimal-model']);
check('stored: renders while the server is unreachable', str_contains(http($nodes['model']), fixture('minimal-model')));
server('down', []);

$edit = http("{$nodes['bumped']}/edit", NULL, TRUE);
check('widget: shows the stored version and offers the newest one', str_contains($edit, 'Version 1 of') && str_contains($edit, 'Use newest version (2)') && str_contains($edit, 'Fetch again (version 1)'));
check('widget: the stored document is not sent to the browser', !str_contains($edit, 'rlmer00000000000/versions/1/') && !str_contains($edit, '&quot;schema&quot;'));
http("{$nodes['bumped']}/edit", form_fields($edit, 'node_article_edit_form') + ['title[0][value]' => 'stored-bumped', 'field_framework[0][url]' => 'https://framework.pub/d/r-lmer/versions/1', 'field_framework[0][action]' => 'keep', 'status[value]' => '1', 'op' => 'Save'], TRUE);
check('widget: Keep leaves version 1', !str_contains(http($nodes['bumped']), '(version 2)'));
$edit = http("{$nodes['bumped']}/edit", NULL, TRUE);
http("{$nodes['bumped']}/edit", form_fields($edit, 'node_article_edit_form') + ['title[0][value]' => 'stored-bumped', 'field_framework[0][url]' => 'https://framework.pub/d/r-lmer/versions/1', 'field_framework[0][action]' => 'newest', 'status[value]' => '1', 'op' => 'Save'], TRUE);
$bumped = http($nodes['bumped']);
check('widget: Use newest version replaces the copy on save', str_contains($bumped, '(version 2)') && str_contains($bumped, 'data-fw-embed-version="2"'));
server('bump', []);

// ---- Takedown: only a definite 404 removes a stored copy.
server('gone', ['minimal-model']);
server('down', ['r-ggplot']);
$counts = json_decode(trim((string) shell_exec($drush . ' php:eval ' . escapeshellarg('echo json_encode(\Drupal::service("framework_embed.takedown")->run());'))), TRUE);
check('takedown: one copy removed', ($counts['removed'] ?? -1) === 1, json_encode($counts));
$model = http($nodes['model']);
check('takedown: removed copy renders a plain link', !str_contains($model, FIGURE) && str_contains($model, '<a href="https://framework.pub/d/minimal-model">'));
check('takedown: unreachable server leaves the copy', str_contains(http($nodes['image']), fixture('r-ggplot')));
check('takedown: editors see why', str_contains(http($nodes['model'], NULL, TRUE), 'stored copy was removed'));
server('gone', []);
server('down', []);

// ---- Settings form.
$form = http("{$base}/admin/config/content/framework-embed", NULL, TRUE);
check('settings: form renders, takedown check on by default', str_contains($form, 'Extra hosts') && (bool) preg_match('#name="takedown" value="1"\s+checked#', $form));
$rejected = http("{$base}/admin/config/content/framework-embed", form_fields($form, 'framework_embed_settings') + ['hosts' => "framework.example.org\n10.0.0.1\nhttps://x.example/path", 'max_ttl' => '3600', 'takedown' => '1', 'op' => 'Save configuration'], TRUE);
check('settings: invalid entries rejected with a message', str_contains($rejected, 'Not allowed: 10.0.0.1, https://x.example/path'));
$saved = http("{$base}/admin/config/content/framework-embed", form_fields($rejected, 'framework_embed_settings') + ['hosts' => "Framework.Example.org\nhttp://localhost:8000/\nframework.pub", 'max_ttl' => '1800', 'op' => 'Save configuration'], TRUE);
preg_match('#<textarea[^>]*name="hosts"[^>]*>(.*?)</textarea>#s', $saved, $area);
check('settings: valid hosts normalized and saved', trim(html_entity_decode($area[1] ?? '')) === "framework.example.org\nhttp://localhost:8000", $area[1] ?? '(no textarea)');
check('settings: takedown check switched off', !preg_match('#name="takedown" value="1"\s+checked#', $saved));
$forged = http("{$base}/admin/config/content/framework-embed", ['form_build_id' => 'x', 'form_token' => 'bad', 'form_id' => 'framework_embed_settings', 'hosts' => 'evil.example', 'max_ttl' => '1', 'op' => 'Save configuration'], TRUE);
check('settings: bad form token rejected', !str_contains($forged, 'evil.example') || str_contains($forged, 'form has become outdated'));

http("{$base}/node/3");
check('extra host: embedded for visitors after it is allowed', str_contains(http("{$base}/node/3"), fixture('minimal-test')));

@unlink($jar);
echo "\n{$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
