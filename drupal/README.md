# Framework Embeds for Drupal

Shows Framework artifacts (regression models, tables, hypothesis tests, figures, and Vega-Lite charts) in Drupal content, without iframes. Requires Drupal 10.3 or 11 and PHP 8.1 or later.

It fetches embed documents from framework.pub, and from self-hosted Framework Cloud servers an administrator allows. It renders them with the `framework-cloud/embed` PHP package.

## Installation

```sh
composer require drupal/framework_embed
drush en framework_embed
```

Until the module and `framework-cloud/embed` are published, add both as path repositories in the site's `composer.json`. Composer ignores repositories declared by dependencies.

```sh
composer config repositories.framework-embed-php '{"type":"path","url":"/path/to/framework-embed/php","options":{"versions":{"framework-cloud/embed":"0.1.0"}}}'
composer config repositories.framework-embed-drupal '{"type":"path","url":"/path/to/framework-embed/drupal","options":{"versions":{"drupal/framework_embed":"0.1.0"}}}'
composer require drupal/framework_embed:0.1.0 framework-cloud/embed:0.1.0
```

## Two tiers

| Tier | Where | Renders from | Network on page view |
|---|---|---|---|
| Stored (preferred) | The "Framework artifact" field | A copy saved with the entity | Never |
| Live | The text format filter, and the "Framework artifact" formatter for Link fields | The `framework_embed` cache bin, filled in the background | Never |

### Stored: the Framework artifact field

Add a "Framework artifact" field to a content type. Its widget takes the artifact URL. Saving fetches the embed document and stores it in the field, with the pinned version URL (`embed_url`) and the time it was fetched. The "Stored artifact" formatter renders from that copy.

| Column | Contents |
|---|---|
| `url` | The URL the editor entered |
| `embed_url` | The pinned version URL from the document |
| `document` | The embed document JSON as the server sent it (`text`, `big`) |
| `fetched_at` | When the copy was fetched |
| `removed` | Set when the takedown check removed the copy |

On the edit form, the widget shows the stored version and an "On save" choice: keep the copy, "Fetch again" the same version, or "Use newest version" when a newer one exists. The copy changes only when the editor saves with one of these chosen. A new URL is fetched on save. A URL that is gone, unreachable, or on a host that is not allowed is a form error. The document stays on the server as a form value; it is not sent to the browser.

### Live: the filter and the Link formatter

Enable "Embed Framework artifacts" on a text format, after "Limit allowed HTML tags", which would otherwise strip the figure. It turns a paragraph that holds only an artifact URL, as text or as a link whose text is the URL, into the artifact. For Link fields, choose the "Framework artifact" formatter.

Live embeds never wait on the network:

- No cached copy: the page shows a link, and the fetch is queued. It runs after the response is sent (the embedder is a `needs_destruction` service), and cron runs the `framework_embed_refresh` queue for anything left.
- Fresh copy: the figure, with max-age set to the copy's remaining `Cache-Control` lifetime.
- Expired copy: the figure, and a background refresh. Copies are kept for seven days past their max-age for this.
- `404`: the copy is dropped and the link stays.

Each embed carries the cache tag `framework_embed_url:<hash>`, invalidated when its copy changes, so cached pages pick up the figure. The Internal Page Cache ignores max-age, so `hook_cron` also invalidates the `framework_embed` tag once per configured lifetime.

### Takedown check

Once a day, cron asks the server about each stored copy on an allowed host. It removes a copy only when the server answers `404` or `410`; timeouts and server errors change nothing. The field keeps its URL and renders a link, and the entity gets a new revision with a log message. Turn the check off in the settings. It is on by default.

### Images

Image artifacts render as `<img>` pointing at the image URL in the embed document. No file is copied.

## Configuration

| Setting | Where |
|---|---|
| Allowed hosts, fresh lifetime, takedown check | Configuration → Content authoring → Framework embeds (`/admin/config/content/framework-embed`). Needs "Administer Framework embeds". |
| Notices for editors | Grant "View Framework embed notices" to roles that should see why an artifact is missing. |

Supported URLs:

- `https://framework.pub/d/{key}` and `…/versions/{n}`
- `https://framework.pub/@{handle}/{project}/{slug}` and `…/versions/{n}`

A host name, such as `framework.example.org`, allows HTTPS only. An origin, such as `http://localhost:8000`, allows exactly that scheme, host, and port. IP addresses, paths, and user info are rejected.

When no copy can be shown, visitors see a link to the artifact. Users with the notice permission also see why.

Requests use Drupal's HTTP client with redirects and cookies off, a five-second timeout, and a 16 MB limit. They happen when an editor saves the field, in background refreshes, and in the takedown check.

The `framework_embed/framework_embed` library holds the stylesheet. `framework_embed/chart` adds the script that draws Vega-Lite charts, and is attached only when a chart is rendered. The script loads vega, vega-lite, and vega-embed from cdn.jsdelivr.net with subresource integrity.

`assets/` is a copy of `php/assets`, made by `make drupal`. Drupal serves library files from the module directory, and `vendor/` is usually outside the web root.

## Services

| Service | Purpose |
|---|---|
| `framework_embed.embedder` | `request()`, the live tier (`lazy()`, `build()`, `refresh()`), and `renderStored()` |
| `framework_embed.fetcher` | Guzzle fetcher. Decorate it to change how embed documents are requested. |
| `framework_embed.takedown` | The daily takedown check |

## Tests

Unit tests need no Drupal:

```sh
make drupal-test       # from the repository root
```

Kernel tests and the end-to-end check need a Composer Drupal project that requires this module (with `drupal/core-dev` for Kernel tests, `drush/drush` for the end-to-end check):

```sh
SIMPLETEST_DB=sqlite://localhost/$PWD/test.sqlite vendor/bin/phpunit -c web/core web/modules/contrib/framework_embed/tests/src
drupal/tests/e2e/run.sh /path/to/drupal-project
```

`run.sh` installs a throwaway SQLite site, enables the module and `framework_embed_test`, and checks pages and forms over HTTP. `framework_embed_test` is a hidden test module that decorates the fetcher with `testing/FixtureServer.php`, which serves `spec/fixtures` instead of contacting a server. Never enable it on a real site.
