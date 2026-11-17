# Framework Embeds for Statamic

Shows Framework artifacts (regression models, tables, hypothesis tests, figures, and Vega-Lite charts) in Statamic templates, without iframes. Requires Statamic 5 or 6 and PHP 8.1 or later. Tested on Statamic 5.74 (Laravel 12) and 6.42 (Laravel 13).

It fetches embed documents from framework.pub, and from self-hosted Framework Cloud servers listed in the config. It renders them with the `framework-cloud/embed` PHP package.

## Installation

```sh
composer require framework-cloud/statamic-embed
php artisan vendor:publish --tag=framework-embed          # CSS and JS to public/vendor/statamic-embed
php artisan vendor:publish --tag=framework-embed-config   # optional: config/framework-embed.php
```

`php artisan statamic:install` also publishes the assets. Publish them again after each update.

Until both packages are published, add them as path repositories in the site's `composer.json`:

```sh
composer config repositories.framework-embed-php '{"type":"path","url":"/path/to/framework-embed/php","options":{"versions":{"framework-cloud/embed":"0.1.0"}}}'
composer config repositories.framework-embed-statamic '{"type":"path","url":"/path/to/framework-embed/statamic","options":{"versions":{"framework-cloud/statamic-embed":"0.1.0"}}}'
composer require framework-cloud/statamic-embed:0.1.0 framework-cloud/embed:0.1.0
```

## Two tiers

| Tier | Where | Renders from | Network on page view |
|---|---|---|---|
| Stored (preferred) | The `framework_artifact` fieldtype | A copy saved in the entry | Never |
| Live | `{{ framework:artifact url="…" }}` and `{{ content \| framework_embeds }}` | The Laravel cache, filled in the background | Never |

### Stored: the framework_artifact fieldtype

Add a "Framework artifact" field to a blueprint. In the control panel it is a plain URL input: the fieldtype reuses Statamic's `text` component, so the addon ships no Vue build. Saving a new URL fetches the artifact and stores it in the entry:

```yaml
artifact:
  url: 'https://framework.pub/d/aB3dE5fG7hJ9kL1m'
  embed_url: 'https://framework.pub/api/embed/v1/artifacts/aB3dE5fG7hJ9kL1m/versions/3'
  document: '{"schema":"framework.embed/v1",…}'
  fetched_at: '2026-11-16T12:00:00Z'
  removed: false
```

`document` is the embed document as the server sent it. `embed_url` pins the version. Saving with the same URL keeps the copy. A URL that is gone, unreachable, or on a host that is not allowed fails validation.

Two entry actions replace stored copies, after a confirmation dialog: "Fetch Framework artifacts again" re-fetches the pinned version, and "Use newest Framework artifact versions" fetches the newest one. They appear in the entry listing and on the entry's actions menu.

Render a stored artifact:

```antlers
{{ framework:artifact field="artifact" }}
{{ artifact:html }}
{{ artifact:title }}, version {{ artifact:version }} of {{ artifact:latest_version }}, fetched {{ artifact:fetched_at }}
```

### Live: the url tag and the modifier

```antlers
{{ framework:artifact url="https://framework.pub/d/aB3dE5fG7hJ9kL1m" }}
{{ content | framework_embeds }}
```

The modifier turns every paragraph in rendered Bard or Markdown content that holds only an artifact URL, as text or as a link whose text is the URL, into the artifact.

Live embeds never wait on the network:

- No cached copy: a link, and a fetch dispatched with `dispatch()->afterResponse()`, or onto the queue when `refresh` is `queue`.
- Fresh copy: the figure.
- Expired copy: the figure, and a background refresh. Copies are kept for seven days past their max-age for this.
- `404`: the copy is dropped and the link stays.

With static caching, a page cached while it showed a link keeps the link until the static cache is cleared. Use the fieldtype on statically cached pages.

### Takedown check

`php artisan framework-embed:takedown` asks the server about each stored copy on an allowed host. It removes a copy only when the server answers `404` or `410`. Timeouts and server errors change nothing. The field keeps its URL and renders a link. The addon schedules the command daily when `takedown` is on, which is the default; it needs Laravel's scheduler (`php artisan schedule:run` from cron). `--force` runs it while switched off.

### Images

Image artifacts render as `<img>` pointing at the image URL in the embed document. No file is copied.

### Layout

```antlers
<head>
    {{ framework:styles }}
</head>
<body>
    {{ template_content }}
    {{ framework:scripts only_if_charts="true" }}
</body>
```

`{{ framework:scripts }}` prints the script that draws Vega-Lite charts. With `only_if_charts="true"` it prints only when a chart was rendered earlier in the request. The script loads vega, vega-lite, and vega-embed from cdn.jsdelivr.net with subresource integrity.

Supported URLs:

- `https://framework.pub/d/{key}` and `…/versions/{n}`
- `https://framework.pub/@{handle}/{project}/{slug}` and `…/versions/{n}`

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `hosts` | `FRAMEWORK_EMBED_HOSTS`, comma-separated | Extra allowed hosts. framework.pub is always allowed. |
| `max_ttl` | `3600` | Longest time a live copy counts as fresh, in seconds. The server's `Cache-Control` can only shorten it. |
| `refresh` | `after_response` | How live fetches run: `after_response` or `queue` |
| `takedown` | `true` | Schedule the daily takedown check |
| `timeout` | `5` | Seconds to wait for a Framework server |
| `cache_store` | default store | Laravel cache store for live copies |

A host name, such as `framework.example.org`, allows HTTPS only. An origin, such as `http://localhost:8000`, allows exactly that scheme, host, and port. IP addresses, paths, and user info are ignored and logged as a warning.

When no copy can be shown, visitors see a link to the artifact. Signed-in users who can open the control panel also see why. Notices are part of the page HTML, so a statically cached page could keep one.

Requests use Laravel's HTTP client with redirects and cookies off and a 16 MB limit. They happen when an editor saves or runs an action, in background refreshes, and in the takedown check.

## Container bindings

| Binding | Purpose |
|---|---|
| `framework-embed.fetcher` | A callable that takes an embed API URL and returns `Framework\Embed\Http\Response`. It must not follow redirects. Rebind it to change the transport, or to serve fixtures in tests. |
| `FrameworkCloud\StatamicEmbed\Embedder` | `render($url)` (live), `renderStored($value)`, `fetchForStorage($url)`, `refresh($url)`, and `convertParagraphs($html)`, scoped to the request |

## Tests

```sh
composer install
vendor/bin/phpunit
```

The tests use Statamic's `AddonTestCase` (Orchestra Testbench) and `../testing/FixtureServer.php`, which serves `../spec/fixtures`. `tests/e2e/check.php` checks a running site over HTTP, saving entries through the control panel's entry API. Its header lists what the site needs.
