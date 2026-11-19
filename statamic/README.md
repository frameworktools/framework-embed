# Framework Embeds for Statamic

Framework Embeds for Statamic displays published Framework models, tables, hypothesis tests, code snippets, figures, and Vega-Lite charts in Statamic templates as static HTML rather than iframes. It requires Statamic 5 or 6 and PHP 8.1 or later, and is tested on Statamic 5.74 (Laravel 12) and 6.42 (Laravel 13).

The addon fetches embed documents from framework.pub and from self-hosted Framework Cloud servers listed in `config/framework-embed.php`, rendering them through the `framework-cloud/embed` PHP package.

## Installation

The addon and `framework-cloud/embed` are not on Packagist. Clone this repository at a release tag and register both directories as path repositories in the site's root `composer.json`:

```sh
git clone --branch v0.6.0 https://github.com/frameworktools/framework-embed.git /path/to/framework-embed
composer config repositories.framework-embed-php '{"type":"path","url":"/path/to/framework-embed/php","options":{"versions":{"framework-cloud/embed":"0.1.0"}}}'
composer config repositories.framework-embed-statamic '{"type":"path","url":"/path/to/framework-embed/statamic","options":{"versions":{"framework-cloud/statamic-embed":"0.1.0"}}}'
composer require framework-cloud/statamic-embed:0.1.0 framework-cloud/embed:0.1.0
php artisan vendor:publish --tag=framework-embed          # CSS and JS to public/vendor/statamic-embed
php artisan vendor:publish --tag=framework-embed-config   # optional: config/framework-embed.php
```

Keep `0.1.0` in these commands to satisfy the `^0.1` constraint in `composer.json`. The installed code comes from the cloned tag. `php artisan statamic:install` also publishes the assets. Re-publish assets after updating the addon.

## Embed tiers

| Tier | Integration | Source | Network on page view |
|---|---|---|---|
| Stored | `framework_artifact` fieldtype | Copy saved in the entry | None |
| Live | `{{ framework:artifact url="…" }}` and `{{ content \| framework_embeds }}` | Laravel cache filled in the background | None |

### Stored embeds

Add a **Framework artifact** field to a blueprint. In the control panel, the field renders as a plain URL input using Statamic's `text` component without a separate Vue build. Saving a new URL fetches the artifact and stores the payload in the entry:

```yaml
artifact:
  url: 'https://framework.pub/d/aB3dE5fG7hJ9kL1m'
  embed_url: 'https://framework.pub/api/embed/v1/artifacts/aB3dE5fG7hJ9kL1m/versions/3'
  document: '{"schema":"framework.embed/v1",…}'
  fetched_at: '2026-11-16T12:00:00Z'
  removed: false
```

`document` holds the raw embed JSON, and `embed_url` pins the version URL. Saving an entry without changing the URL preserves the stored copy. Missing, unreachable, or disallowed URLs fail validation.

Two entry actions update stored copies after confirmation: **Fetch stored Framework artifact versions again** re-fetches the pinned version, and **Use newest Framework artifact versions** fetches the latest version. Both appear in the entry listing and the entry actions menu.

Render a stored artifact in Antlers:

```antlers
{{ framework:artifact field="artifact" }}
{{ artifact:html }}
{{ artifact:title }}, version {{ artifact:version }} of {{ artifact:latest_version }}, fetched {{ artifact:fetched_at }}
```

### Live embeds

```antlers
{{ framework:artifact url="https://framework.pub/d/aB3dE5fG7hJ9kL1m" }}
{{ content | framework_embeds }}
```

The `framework_embeds` modifier replaces any paragraph in rendered Bard or Markdown HTML that contains solely an artifact URL (as plain text or an autolink) with the embedded artifact.

Live embeds never block page rendering on network requests:

- **No cached copy.** The addon renders a link and dispatches a refresh via `dispatch()->afterResponse()`, or onto the queue when `refresh` is `queue`.
- **Fresh copy.** The addon renders the cached artifact.
- **Expired copy.** The addon renders the cached artifact and schedules a background refresh. Copies persist for seven days past `max-age` to support stale-while-revalidate rendering.
- **`404` or `410` response.** The refresh removes the cached copy and leaves a plain link.

With static caching enabled, a page cached while showing a link retains that link until the static cache is cleared. Prefer the `framework_artifact` fieldtype on statically cached pages.

### Takedown check

`php artisan framework-embed:takedown` checks each stored copy on an allowed host. It removes a stored copy only when the server returns `404` or `410`, leaving timeouts and server errors untouched. The field retains its URL and renders a plain link. The addon schedules this command daily when `takedown` is enabled (the default), requiring the Laravel scheduler (`php artisan schedule:run` from cron). Pass `--force` to run the check while `takedown` is disabled.

### Figure images

Image artifacts render as `<img>` elements pointing to the image URL in the embed document without copying files into the site.

### Layout tags

```antlers
<head>
    {{ framework:styles }}
</head>
<body>
    {{ template_content }}
    {{ framework:scripts only_if_charts="true" }}
</body>
```

`{{ framework:scripts }}` outputs the script tag that initializes Vega-Lite charts, opens variable tips on hover, and shows the copy and output buttons on code snippets. With `only_if_charts="true"`, it outputs the script tag only when a chart, a variable tip, or a code snippet rendered earlier in the same request. When a chart nears the viewport, the script loads `vega`, `vega-lite`, and `vega-embed` from `cdn.jsdelivr.net` with subresource integrity.

Supported URLs:

- `https://framework.pub/d/{key}` and `…/versions/{n}`
- `https://framework.pub/@{handle}/{project}/{slug}` and `…/versions/{n}`

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `hosts` | `FRAMEWORK_EMBED_HOSTS`, comma-separated | Additional allowed hosts alongside framework.pub |
| `max_ttl` | `3600` | Maximum cache lifetime in seconds for live embeds, subject to shorter `Cache-Control` headers |
| `refresh` | `after_response` | Background fetch mode: `after_response` or `queue` |
| `takedown` | `true` | Schedule the daily takedown check |
| `timeout` | `5` | Request timeout in seconds for Framework Cloud servers |
| `cache_store` | default store | Laravel cache store for live embeds |

A bare host name such as `framework.example.org` allows HTTPS on the default port. An origin such as `http://localhost:8000` allows that exact scheme, host, and port. IP addresses, paths, and user info are ignored and logged as warnings.

When no copy is available, visitors see a link to the artifact, and signed-in users with control panel access also see a notice explaining why. Because notices render in page HTML, avoid caching control panel sessions in static page caches.

HTTP requests use Laravel's HTTP client with redirects and cookies disabled and a 16 MB response limit. Requests run on entry save or action execution, during background refreshes, and during takedown checks.

## Container bindings

| Binding | Purpose |
|---|---|
| `framework-embed.fetcher` | Callable accepting an embed API URL and returning `Framework\Embed\Http\Response` without following redirects. Rebind it to customize transport or serve fixtures in tests. |
| `FrameworkCloud\StatamicEmbed\Embedder` | Request-scoped service providing `render($url)`, `renderStored($value)`, `fetchForStorage($url)`, `refresh($url)`, and `convertParagraphs($html)` |

## Tests

```sh
composer install
vendor/bin/phpunit
```

The test suite uses Statamic's `AddonTestCase` (Orchestra Testbench) with `../testing/FixtureServer.php`. `tests/e2e/check.php` runs end-to-end HTTP checks against a live Statamic site through the control panel entry API.
