# Framework Embeds for Drupal

Framework Embeds for Drupal displays published Framework models, tables, hypothesis tests, code snippets, figures, and Vega-Lite charts in Drupal content as static HTML rather than iframes. It requires Drupal 10.3 or 11 and PHP 8.1 or later.

The module fetches embed documents from framework.pub and from self-hosted Framework Cloud servers configured by an administrator, rendering them through the `framework-cloud/embed` PHP package.

## Installation

The module and `framework-cloud/embed` are not on Packagist or drupal.org. Clone this repository at a release tag and register both directories as path repositories in the site's root `composer.json`:

```sh
git clone --branch v0.9.1 https://github.com/frameworktools/framework-embed.git /path/to/framework-embed
composer config repositories.framework-embed-php '{"type":"path","url":"/path/to/framework-embed/php","options":{"versions":{"framework-cloud/embed":"0.1.0"}}}'
composer config repositories.framework-embed-drupal '{"type":"path","url":"/path/to/framework-embed/drupal","options":{"versions":{"drupal/framework_embed":"0.1.0"}}}'
composer require drupal/framework_embed:0.1.0 framework-cloud/embed:0.1.0
drush en framework_embed
```

Keep `0.1.0` in these commands to satisfy the `^0.1` constraint in `composer.json`. The installed code comes from the cloned tag.

## Embed tiers

| Tier | Integration | Source | Network on page view |
|---|---|---|---|
| Stored | **Framework artifact** field | Copy saved with the entity | None |
| Live | Text format filter and **Framework artifact** formatter for Link fields | `framework_embed` cache bin filled in the background | None |

### Stored embeds

Add a **Framework artifact** field to a content type. Its widget accepts an artifact URL. Saving fetches the embed document and stores it in the field alongside the pinned version URL (`embed_url`) and timestamp. The **Stored artifact** formatter renders from that saved copy.

| Column | Contents |
|---|---|
| `url` | Artifact URL entered by the editor |
| `embed_url` | Pinned version URL from the embed document |
| `document` | Raw embed document JSON (`text`, `big`) |
| `fetched_at` | Unix timestamp when the copy was fetched |
| `removed` | Flag set when the takedown check removed the copy |

On the entity edit form, the widget displays the stored version and a **Save action** selector: keep the stored copy, **Fetch again** for the pinned version, or **Use newest version** when a newer version exists. The copy updates only when the editor saves with a refresh option selected or changes the URL. A missing, unreachable, or disallowed URL produces a validation error. The raw JSON document remains a server-side form value and is not sent to the browser.

### Live embeds

Enable **Embed Framework artifacts** on a text format after **Limit allowed HTML tags**, which would otherwise strip the `<figure>` markup. The filter replaces any paragraph containing solely an artifact URL (as plain text or an autolink) with the embedded artifact. For Link fields, select the **Framework artifact** formatter.

Live embeds never block page rendering on network requests:

- **No cached copy.** The module renders a plain link and queues a refresh. The refresh runs after the response is sent (`needs_destruction`), with cron processing the `framework_embed_refresh` queue as a fallback.
- **Fresh copy.** The module renders the artifact with `max-age` set to the remaining `Cache-Control` lifetime.
- **Expired copy.** The module renders the cached artifact and schedules a background refresh. Copies persist for seven days past `max-age` to support stale-while-revalidate rendering.
- **`404` or `410` response.** The refresh removes the cached copy and leaves a plain link.

Each embed carries the cache tag `framework_embed_url:<hash>`, invalidated when its cached copy updates. Because Drupal's Internal Page Cache ignores `max-age`, `hook_cron` also invalidates the `framework_embed` tag once per configured cache lifetime.

### Takedown check

Once a day, cron checks each stored copy on an allowed host. It removes a stored copy only when the server returns `404` or `410`, leaving timeouts and server errors untouched. The field retains its URL, renders a plain link, and records a new entity revision when revisions are enabled. Disable this check on the settings form if needed.

### Figure images

Image artifacts render as `<img>` elements pointing to the image URL in the embed document without copying files into Drupal.

## Configuration

| Setting | Location |
|---|---|
| Allowed hosts, cache lifetime, takedown check | **Configuration → Content authoring → Framework embeds** (`/admin/config/content/framework-embed`), requiring **Administer Framework embeds** |
| Editor notices | Grant **View Framework embed notices** to roles that should see notices when an embed is unavailable |

Supported URLs:

- `https://framework.pub/d/{key}` and `…/versions/{n}`
- `https://framework.pub/@{handle}/{project}/{slug}` and `…/versions/{n}`

A bare host name such as `framework.example.org` allows HTTPS on the default port. An origin such as `http://localhost:8000` allows that exact scheme, host, and port. IP addresses, paths, and user info are rejected.

When no copy is available, visitors see a link to the artifact, and users with **View Framework embed notices** also see the reason.

HTTP requests use Drupal's Guzzle client with redirects and cookies disabled, a five-second timeout, and a 16 MB response limit. Requests run on field save, during background refreshes, and during takedown checks.

The `framework_embed/framework_embed` Drupal asset library provides the stylesheet. `framework_embed/chart` loads the script for Vega-Lite charts, variable tips, and code snippet buttons, and attaches only when one of them is rendered. When a chart nears the viewport, that script loads `vega`, `vega-lite`, and `vega-embed` from `cdn.jsdelivr.net` with subresource integrity.

`assets/` contains copies of `php/assets` updated by `make drupal`, since Drupal serves asset files from the module directory while `vendor/` typically sits outside the web root.

## Services

| Service | Purpose |
|---|---|
| `framework_embed.embedder` | `request()`, live rendering (`lazy()`, `build()`, `refresh()`), and `renderStored()` |
| `framework_embed.fetcher` | Guzzle HTTP fetcher, decoratable for custom transport or tests |
| `framework_embed.takedown` | Daily takedown check |

## Tests

Unit tests run without a Drupal installation:

```sh
make drupal-test       # from the repository root
```

Kernel tests and end-to-end checks run inside a Composer Drupal project that requires `drupal/framework_embed` (`drupal/core-dev` for Kernel tests, `drush/drush` for end-to-end checks):

```sh
SIMPLETEST_DB=sqlite://localhost/$PWD/test.sqlite vendor/bin/phpunit -c web/core web/modules/contrib/framework_embed/tests/src
drupal/tests/e2e/run.sh /path/to/drupal-project
```

`run.sh` installs a temporary SQLite site, enables `framework_embed` and `framework_embed_test`, and exercises pages and forms over HTTP. `framework_embed_test` is a hidden test module that replaces the HTTP fetcher with `testing/FixtureServer.php`. Never enable `framework_embed_test` on a production site.
