<?php
/**
 * End-to-end checks against a running Playground site (see run.sh).
 * Usage: php check.php http://127.0.0.1:9455 /path/to/spec/fixtures
 */

declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://127.0.0.1:9455', '/');
$fixtures = $argv[2] ?? dirname(__DIR__, 3) . '/spec/fixtures';
$jar = tempnam(sys_get_temp_dir(), 'fw-wp-cookies');
$failures = 0;
$passes = 0;
$pages = [];

function http(string $url, ?array $post = null, bool $cookies = false, ?string $json = null, ?int &$status = null): string
{
    global $jar, $pages;
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120]);
    if ($cookies) {
        curl_setopt_array($handle, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    }
    if ($post !== null) {
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    }
    if ($json !== null) {
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    $body = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $pages[] = $body;

    return $body;
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    $ok ? $passes++ : $failures++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . (! $ok && $detail !== '' ? "\n     " . substr($detail, 0, 600) : '') . "\n";
}

function fixture(string $name): string
{
    global $fixtures;

    return rtrim((string) file_get_contents("{$fixtures}/{$name}.html"), "\n");
}

function post(string $slug, string $query = ''): string
{
    global $base;

    return http("{$base}/?name={$slug}{$query}");
}

function test(string $action, string $names = ''): string
{
    global $base;

    return http("{$base}/?framework_embed_test={$action}&names=" . rawurlencode($names));
}

function requests(): int
{
    return (int) test('requests');
}

function cron(): void
{
    global $base;
    http("{$base}/wp-cron.php");
}

/** Block comment as WordPress serializes it (serialize_block_attributes). */
function block(array $attrs): string
{
    $json = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $json = strtr($json, ['--' => '\\u002d\\u002d', '<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026', '\\"' => '\\u0022']);

    return "<!-- wp:framework/artifact {$json} /-->";
}

function rest(string $route, array $data, ?int &$status = null, bool $editor = true): array
{
    global $base;
    $body = http("{$base}/?rest_route=" . rawurlencode($route) . ($editor ? '&as_editor=1' : ''), null, false, json_encode($data), $status);

    return (array) json_decode($body, true);
}

$live = ['classic', 'block', 'embed-block', 'shortcode', 'all'];

// ---- Live tier, first views: links, and no network during the render.
$before = requests();
$first = [];
foreach ($live as $slug) {
    $first[$slug] = post($slug);
}
check('live: first views make no requests while rendering', requests() === $before);
check('live: first views show no figure', ! array_filter($first, fn ($html) => str_contains($html, 'class="fw-embed ')));
check('live: classic first view is a plain link', str_contains($first['classic'], '<a href="https://framework.pub/d/minimal-model">https://framework.pub/d/minimal-model</a>'));
check('live: visitors see no notice', ! str_contains($first['classic'], 'framework-embed-notice'));
check('live: editors see the fetching notice', str_contains(post('classic', '&as_editor=1'), 'being fetched in the background'));

// ---- Background jobs run (WP-Cron), then views render from the cache.
cron();
$fetched = requests();
check('cron: background jobs fetched the documents', $fetched > $before, "{$before} -> {$fetched}");

$classic = post('classic');
check('classic: URL on its own line renders the fixture HTML exactly', str_contains($classic, fixture('minimal-model')));
check('classic: stylesheet enqueued', str_contains($classic, "id='framework-embed-css'"));
check('classic: no chart script without a chart', ! str_contains($classic, 'framework-embed.iife.js'));
check('classic: wpautop left the figure alone', ! preg_match('#<figcaption[^>]*>(?:(?!</figcaption>).)*<br#s', $classic));
check('classic: surrounding paragraphs kept', str_contains($classic, '<p>Before the model.</p>') && str_contains($classic, '<p>After the model.</p>'));
file_put_contents(sys_get_temp_dir() . '/framework-embed-classic.html', $classic);
check('block without a stored copy: live tier renders exactly', str_contains(post('block'), fixture('r-glm-logit')));
check('core/embed block: path URL renders exactly', str_contains(post('embed-block'), fixture('r-tibble')));
$short = post('shortcode');
check('shortcode: chart renders exactly', str_contains($short, fixture('python-altair')));
preg_match_all('#<script[^>]*framework-embed[^>]*>#', $short, $scripts);
$deferred = array_filter($scripts[0], fn ($tag) => str_contains($tag, 'framework-embed.iife.js') && (bool) preg_match('#\sdefer[\s>]#', $tag));
check('shortcode: chart script enqueued, deferred', $deferred !== [], implode(' ', $scripts[0]));
$all = post('all');
$missing = [];
foreach (glob("{$fixtures}/*.json") as $file) {
    if (! str_contains($all, fixture(basename($file, '.json')))) {
        $missing[] = basename($file, '.json');
    }
}
check('all fixtures: every spec/fixtures/*.html appears exactly', $missing === [], 'missing: ' . implode(', ', $missing));
$cached = requests();
post('all');
post('classic');
check('cache: later views make no requests', requests() === $cached);

// ---- Live failures.
$gone = post('missing');
cron();
$gone = post('missing');
check('live 404: plain link, no figure', str_contains($gone, '<a href="https://framework.pub/d/gone">') && ! str_contains($gone, 'class="fw-embed '));
check('live network failure: plain link', str_contains($gone, '<a href="https://framework.pub/d/down">'));
check('live: disallowed host is not embedded', str_contains($gone, 'evil.example/d/minimal-model') && ! str_contains($gone, 'class="fw-embed '));
$editor = post('missing', '&as_editor=1');
check('editor: 404 notice', str_contains($editor, 'private, password-protected, or deleted'));

// ---- Stale-while-revalidate, and a 404 on refresh drops the copy.
test('gone', 'r-tibble');
check('expire: cached copies marked expired', (int) test('expire') > 0);
$stale = requests();
check('stale: expired copy still renders', str_contains(post('classic'), fixture('minimal-model')) && str_contains(post('embed-block'), fixture('r-tibble')));
check('stale: rendering made no requests', requests() === $stale);
cron();
check('stale: refresh ran in the background', requests() > $stale);
check('stale: refreshed copy renders', str_contains(post('classic'), fixture('minimal-model')));
$dropped = post('embed-block');
check('refresh 404: copy dropped, plain link', ! str_contains($dropped, 'class="fw-embed ') && str_contains($dropped, '<a href="https://framework.pub/@ada/admissions/r-tibble">'));
test('gone', '');

// ---- Stored tier: the editor's fetch endpoint.
$fetch = rest('/framework-embed/v1/fetch', ['url' => 'https://framework.pub/d/minimal-model'], $status);
check('fetch endpoint: returns the raw document and pins the version URL', $status === 200 && ($fetch['embedUrl'] ?? '') === 'https://framework.pub/api/embed/v1/artifacts/minimalmodel0000/versions/1' && json_decode($fetch['document'] ?? '', true)['key'] === 'minimalmodel0000', json_encode($fetch));
rest('/framework-embed/v1/fetch', ['url' => 'https://framework.pub/d/minimal-model'], $status, false);
check('fetch endpoint: visitors get 401', $status === 401, (string) $status);
rest('/framework-embed/v1/fetch', ['url' => 'https://evil.example/d/minimal-model'], $status);
check('fetch endpoint: disallowed host is 400 and not fetched', $status === 400, (string) $status);
test('gone', 'page');
rest('/framework-embed/v1/fetch', ['url' => 'https://framework.pub/d/page'], $status);
check('fetch endpoint: 404 artifact is 404', $status === 404, (string) $status);
test('gone', '');

$stored = [];
foreach (['model' => 'https://framework.pub/d/minimal-model', 'image' => 'https://framework.pub/d/r-ggplot', 'down' => 'https://framework.pub/d/stata-stcox', 'bumped' => 'https://framework.pub/d/r-lmer/versions/1'] as $slug => $url) {
    if ($slug === 'bumped') {
        test('bump', 'r-lmer');
    }
    $result = rest('/framework-embed/v1/fetch', ['url' => $url]);
    $content = block(['url' => $url, 'embedUrl' => $result['embedUrl'], 'document' => $result['document'], 'fetchedAt' => $result['fetchedAt']]);
    $created = rest('/wp/v2/posts', ['title' => "stored-{$slug}", 'slug' => "stored-{$slug}", 'status' => 'publish', 'content' => $content], $status);
    $stored[$slug] = $result;
    check("stored {$slug}: post created through REST", $status === 201, json_encode($created));
}
$views = requests();
check('stored model: renders the fixture HTML exactly', str_contains(post('stored-model'), fixture('minimal-model')));
$image = post('stored-image');
check('stored image: renders exactly, <img> points at the stored image URL', str_contains($image, fixture('r-ggplot')) && str_contains($image, 'src="https://framework.pub/api/embed/v1/artifacts/rggplot000000000/versions/1/image"'));
check('stored: rendering made no requests', requests() === $views);
$mediaList = json_decode(http("{$base}/?rest_route=/wp/v2/media&as_editor=1"), true);
check('stored image: no file copied into the media library', is_array($mediaList) && count($mediaList) === 0, json_encode($mediaList));
check('stored bumped: pinned version 1 knows version 2 exists', ($stored['bumped']['version'] ?? 0) === 1 && ($stored['bumped']['latestVersion'] ?? 0) === 2);
$again = rest('/framework-embed/v1/fetch', ['url' => $stored['bumped']['embedUrl']]);
check('Fetch again: same pinned version', ($again['version'] ?? 0) === 1 && $again['embedUrl'] === $stored['bumped']['embedUrl']);
$newest = rest('/framework-embed/v1/fetch', ['url' => $stored['bumped']['embedUrl'], 'newest' => true]);
check('Use newest version: version 2, pinned to /versions/2', ($newest['version'] ?? 0) === 2 && str_ends_with((string) ($newest['embedUrl'] ?? ''), '/versions/2'), json_encode(array_diff_key($newest, ['document' => 1, 'html' => 1])));
test('bump', '');

// ---- Takedown: only a definite 404 removes a stored copy.
test('gone', 'minimal-model');
test('down', 'stata-stcox');
$counts = json_decode(test('takedown'), true);
check('takedown: one copy removed', ($counts['removed'] ?? -1) === 1, json_encode($counts));
$model = post('stored-model');
check('takedown: removed copy renders a plain link', ! str_contains($model, 'class="fw-embed ') && str_contains($model, '<a href="https://framework.pub/d/minimal-model">'));
check('takedown: unreachable server leaves the copy', str_contains(post('stored-down'), fixture('stata-stcox')));
check('takedown: unaffected copy kept', str_contains(post('stored-image'), fixture('r-ggplot')));
check('takedown: editors see why', str_contains(post('stored-model', '&as_editor=1'), 'stored copy was removed'));
test('gone', '');
test('down', '');

// ---- Settings: capability, nonce, host validation, and the takedown switch.
check('settings: hidden from visitors', ! str_contains(http("{$base}/wp-admin/options-general.php?page=framework-embed"), 'Extra hosts'));
http("{$base}/wp-login.php", null, true);
http("{$base}/wp-login.php", ['log' => 'admin', 'pwd' => 'password', 'rememberme' => 'forever', 'wp-submit' => 'Log In', 'testcookie' => '1'], true);
$page = http("{$base}/wp-admin/options-general.php?page=framework-embed", null, true);
check('settings: page renders for an administrator, takedown check on by default', str_contains($page, 'Extra hosts') && (bool) preg_match('#name="framework_embed_takedown" value="1"\s+checked#', $page));
preg_match('#name="_wpnonce" value="([^"]+)"#', $page, $nonce);
$forged = http("{$base}/wp-admin/options.php", ['option_page' => 'framework-embed', 'action' => 'update', '_wpnonce' => 'bad', 'framework_embed_hosts' => 'framework.example.org'], true);
check('settings: bad nonce rejected', str_contains($forged, 'link you followed has expired') || str_contains($forged, 'Are you sure'));
check('extra host: not embedded before it is allowed', ! str_contains(post('extra-host'), 'class="fw-embed '));
$saved = http("{$base}/wp-admin/options.php", [
    'option_page' => 'framework-embed',
    'action' => 'update',
    '_wpnonce' => $nonce[1] ?? '',
    '_wp_http_referer' => '/wp-admin/options-general.php?page=framework-embed',
    'framework_embed_hosts' => "Framework.Example.org\nhttp://localhost:8000/\n10.0.0.1\nhttps://x.example/path\nframework.pub\nuser@evil.example",
    'framework_embed_takedown' => '0',
], true);
preg_match('#<textarea[^>]*framework-embed-hosts[^>]*>(.*?)</textarea>#s', $saved, $area);
check('settings: valid hosts normalized and saved', trim(html_entity_decode($area[1] ?? '')) === "framework.example.org\nhttp://localhost:8000", $area[1] ?? '(no textarea)');
check('settings: invalid entries reported', str_contains($saved, 'Not saved: 10.0.0.1, https://x.example/path, user@evil.example'));
check('settings: takedown check switched off', ! preg_match('#name="framework_embed_takedown" value="1"\s+checked#', $saved));
check('takedown: does nothing when switched off', json_decode(test('takedown'), true) === ['checked' => 0, 'removed' => 0]);
post('extra-host');
cron();
check('extra host: embedded after it is allowed', str_contains(post('extra-host'), fixture('minimal-test')));

$noticeHits = array_filter($pages, static fn (string $html): bool => (bool) preg_match('#<b>(Warning|Notice|Deprecated|Fatal error)</b>|PHP (Warning|Notice|Deprecated)#', $html));
check('no PHP warnings, notices, or deprecations with WP_DEBUG on', $noticeHits === [], substr(implode("\n", $noticeHits), 0, 2000));

@unlink($jar);
echo "\n{$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
