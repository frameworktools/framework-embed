<?php

/**
 * End-to-end checks against a running Statamic site (see README.md, Tests).
 *
 * The site binds framework-embed.fetcher to testing/FixtureServer.php, reading
 * its lists from storage/framework-embed-lists.json and logging requests to
 * storage/framework-embed-requests.log. It has a page at /embeds (url tag and
 * modifier), and an "articles" collection whose blueprint "article" has a
 * framework_artifact field "artifact" and a Bard field "body", with the
 * template `{{ framework:artifact field="artifact" }}{{ body | framework_embeds }}`.
 *
 * Usage: php check.php <base URL> <spec/fixtures> <site directory> <cp email> <cp password>
 */

declare(strict_types=1);

[, $base, $fixtures, $site, $email, $password] = $argv;
$base = rtrim($base, '/');
$jar = tempnam(sys_get_temp_dir(), 'fw-statamic-cookies');
$failures = 0;
$passes = 0;

function http(string $url, ?array $post = null, bool $cookies = false, string $method = 'POST', ?int &$status = null): string
{
    global $jar, $csrf;
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60]);
    if ($cookies) {
        curl_setopt_array($handle, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    }
    if ($post !== null) {
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($post),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Requested-With: XMLHttpRequest', 'X-CSRF-TOKEN: ' . ($csrf ?? '')],
        ]);
    }
    $body = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

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

function lists(array $lists): void
{
    global $site;
    file_put_contents("{$site}/storage/framework-embed-lists.json", json_encode($lists));
}

function requests(): int
{
    global $site;

    return count(array_filter(explode("\n", (string) @file_get_contents("{$site}/storage/framework-embed-requests.log"))));
}

function artisan(string $command): string
{
    global $site;

    return (string) shell_exec('cd ' . escapeshellarg($site) . ' && php artisan ' . $command . ' 2>&1');
}

const FIGURE = 'class="fw-embed ';
lists([]);

// ---- Live tier: url tag and modifier. First view links; the fetch runs after the response.
$before = requests();
$first = http("{$base}/embeds");
check('live: first view shows links, no figure', str_contains($first, '<a href="https://framework.pub/d/minimal-model">') && ! str_contains($first, FIGURE));
$page = http("{$base}/embeds");
check('live: fetched after the first response', requests() > $before, "requests {$before} -> " . requests());
check('tag: {{ framework:artifact url }} renders the fixture HTML exactly', str_contains($page, fixture('minimal-model')));
check('modifier: Markdown URL paragraph renders exactly', str_contains($page, fixture('r-glm-logit')));
check('modifier: Markdown autolink paragraph (path URL, chart) renders exactly', str_contains($page, fixture('python-altair')));
check('modifier: URL inside a sentence left alone', str_contains($page, 'See https://framework.pub/d/r-tibble in a sentence.'));
check('modifier: disallowed host left alone', str_contains($page, '<p>https://evil.example/d/minimal-model</p>'));
check('styles tag: stylesheet link', (bool) preg_match('#<link rel="stylesheet" href="[^"]*/vendor/statamic-embed/framework-embed\.css\?v=[0-9a-f]{12}">#', $page));
check('scripts tag: chart script after a chart', (bool) preg_match('#<script src="[^"]*/vendor/statamic-embed/framework-embed\.iife\.js\?v=[0-9a-f]{12}" defer></script>#', $page));
check('visitor: 404 and network failure render plain links', str_contains($page, '<a href="https://framework.pub/d/gone">') && str_contains($page, '<a href="https://framework.pub/d/down">'));
check('visitor: no notices', ! str_contains($page, 'framework-embed-notice'));
$cached = requests();
http("{$base}/embeds");
check('live: later views make no requests', requests() === $cached);
preg_match('#href="([^"]+framework-embed\.css)\?#', $page, $css);
check('published stylesheet is served', isset($css[1]) && str_contains(http($css[1]), '.fw-embed'));

// ---- Control panel login.
$login = http("{$base}/cp/auth/login", null, true);
preg_match('#name="_token" value="([^"]+)"#', $login, $token) || preg_match('#<meta name="csrf-token" content="([^"]+)"#', $login, $token);
$handle = curl_init("{$base}/cp/auth/login");
curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['_token' => $token[1] ?? '', 'email' => $email, 'password' => $password])]);
curl_exec($handle);
// The cookie jar is written when the handle is destroyed (curl_close does nothing since PHP 8).
unset($handle);
$cp = http("{$base}/cp/collections/articles/entries/create/default", null, true);
preg_match('#"csrfToken":"([^"]+)"#', $cp, $meta) || preg_match('#<meta name="csrf-token" content="([^"]+)"#', $cp, $meta);
$csrf = $meta[1] ?? '';
check('control panel: signed in', $csrf !== '' && str_contains($cp, 'framework_artifact'), substr($cp, 0, 200));
$editor = http("{$base}/embeds", null, true);
check('editor: 404 notice on the live page', str_contains($editor, 'private, password-protected, or deleted'));

// ---- Stored tier: entries saved through the control panel's entry API.
$create = function (string $slug, string $url) use ($base): array {
    $body = http("{$base}/cp/collections/articles/entries/default", ['title' => $slug, 'slug' => $slug, 'artifact' => $url, '_blueprint' => 'article', 'published' => true, 'body' => null], true, 'POST', $status);

    return [$status, (array) json_decode($body, true)];
};
[$status, $answer] = $create('stored-gone', 'https://framework.pub/d/gone');
check('fieldtype: a 404 artifact is a validation error', $status === 422 && str_contains(json_encode($answer), 'private, password-protected, or deleted'), "{$status} " . json_encode($answer));
[$status, $answer] = $create('stored-evil', 'https://evil.example/d/minimal-model');
check('fieldtype: a disallowed host is a validation error', $status === 422 && str_contains(json_encode($answer), 'not a Framework artifact on an allowed host'), "{$status} " . json_encode($answer));

$ids = [];
foreach (['model' => 'https://framework.pub/d/minimal-model', 'image' => 'https://framework.pub/d/r-ggplot', 'bumped' => 'https://framework.pub/d/r-lmer/versions/1'] as $slug => $url) {
    lists($slug === 'bumped' ? ['bump' => ['r-lmer']] : []);
    [$status, $answer] = $create("stored-{$slug}", $url);
    $ids[$slug] = $answer['data']['id'] ?? null;
    check("stored {$slug}: saved through the control panel", $status === 200 && $ids[$slug] !== null, "{$status} " . json_encode($answer));
}
$yaml = (string) @file_get_contents("{$site}/content/collections/articles/stored-model.md");
check('stored: the entry file holds the document and pinned version URL', str_contains($yaml, 'embed_url: \'https://framework.pub/api/embed/v1/artifacts/minimalmodel0000/versions/1\'') && str_contains($yaml, 'minimalmodel0000') && str_contains($yaml, 'document:'), substr($yaml, 0, 400));
$views = requests();
check('request counter works (guards the no-request checks)', $views > 0);
check('stored model: renders the fixture HTML exactly', str_contains(http("{$base}/articles/stored-model"), fixture('minimal-model')));
$image = http("{$base}/articles/stored-image");
check('stored image: renders exactly, <img> points at the stored image URL', str_contains($image, fixture('r-ggplot')) && str_contains($image, 'src="https://framework.pub/api/embed/v1/artifacts/rggplot000000000/versions/1/image"'));
lists(['down' => ['minimal-model']]);
check('stored: renders while the server is unreachable', str_contains(http("{$base}/articles/stored-model"), fixture('minimal-model')));
check('stored: views make no requests', requests() === $views);

// ---- Actions: Fetch again (pinned) and Use newest version, with confirmation in the CP.
lists(['bump' => ['r-lmer']]);
$listed = http("{$base}/cp/collections/articles/entries/actions/list", ['selections' => [$ids['bumped']]], true);
check('actions: offered for an entry with a stored artifact', str_contains($listed, 'fetch_artifacts_again') && str_contains($listed, 'use_newest_artifact_versions') && str_contains($listed, 'replace its stored copy'), substr($listed, 0, 400));
http("{$base}/cp/collections/articles/entries/actions", ['action' => 'fetch_artifacts_again', 'selections' => [$ids['bumped']], 'values' => [], 'context' => []], true, 'POST', $status);
check('Fetch again: keeps the pinned version', $status === 200 && str_contains(http("{$base}/articles/stored-bumped"), 'data-fw-embed-version="1"'), (string) $status);
http("{$base}/cp/collections/articles/entries/actions", ['action' => 'use_newest_artifact_versions', 'selections' => [$ids['bumped']], 'values' => [], 'context' => []], true, 'POST', $status);
$bumped = http("{$base}/articles/stored-bumped");
check('Use newest version: replaces the copy with version 2', str_contains($bumped, 'data-fw-embed-version="2"') && str_contains($bumped, '(version 2)'));

// ---- Takedown: only a definite 404 removes a stored copy.
lists(['gone' => ['minimal-model'], 'down' => ['r-ggplot']]);
$out = artisan('framework-embed:takedown');
check('takedown: one copy removed', str_contains($out, '"removed":1'), $out);
$model = http("{$base}/articles/stored-model");
check('takedown: removed copy renders a plain link', ! str_contains($model, FIGURE) && str_contains($model, '<a href="https://framework.pub/d/minimal-model">'));
check('takedown: unreachable server leaves the copy', str_contains(http("{$base}/articles/stored-image"), fixture('r-ggplot')));
check('takedown: editors see why', str_contains(http("{$base}/articles/stored-model", null, true), 'stored copy was removed'));
check('takedown: scheduled daily', str_contains(artisan('schedule:list'), 'framework-embed:takedown'));
lists([]);

// ---- Bard content through the modifier (live).
http("{$base}/articles/bard");
$bard = http("{$base}/articles/bard");
check('Bard: text URL paragraph renders exactly', str_contains($bard, fixture('stata-regress')));
check('Bard: linked URL paragraph renders exactly', str_contains($bard, fixture('r-ttest-welch')));

@unlink($jar);
echo "\n{$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
