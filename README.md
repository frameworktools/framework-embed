# framework-embed

`framework-embed` displays published Framework artifacts on blogs, personal sites, and web pages. It includes plugins for WordPress, Drupal, and Statamic, a `<framework-artifact>` custom element for HTML pages, and JavaScript and PHP renderers.

Each public or unlisted artifact on [framework.pub](https://framework.pub) or a self-hosted Framework Cloud server provides a `framework.embed/v1` document. The renderers convert that document into escaped HTML styled with CSS custom properties rather than rendering an iframe. Embedding requires no account or API key on the receiving site, and the code is MIT licensed.

## Supported artifacts

- **Regression models.** One coefficient table with estimates, exponentiated odds, hazard, or rate ratios when applicable, standard errors, test statistics, p-values, and confidence intervals. Fit statistics and additional tables appear in a collapsible **Model details** panel.
- **Tables.** Static HTML tables displaying the first 25 rows with a link to the full table.
- **Hypothesis tests.** Test statistic, p-value, and estimate with its confidence interval, with additional parameters under **Test details**.
- **Figures.** SVG, PNG, JPEG, GIF, and WebP images.
- **Charts.** Interactive Vega-Lite charts rendered with `vega-embed`.
- **Other artifacts.** Link cards for HTML documents, Quarto sites, PDFs, and downloadable files.

## CMS plugins

Each plugin renders through the PHP package, contacts only framework.pub and servers configured by an administrator, and never blocks page rendering on network requests. Plugins support stored and live embeds:

| Tier | Used by | Behavior |
|---|---|---|
| Stored | WordPress block, Drupal field, Statamic fieldtype | Inserting a URL fetches the embed document once and saves it with the content, pinned to its version URL. Pages render from that saved copy. **Fetch again** (same version) and **Use newest version** replace the copy only after author confirmation. |
| Live | Pasted URLs, shortcodes, text filters, Link formatters, Antlers `url=` tag and modifier | The first view renders a link and queues a background fetch. Subsequent views render from a cache governed by `Cache-Control`. Expired copies continue rendering while refreshing in the background. A `404` or `410` response removes the cached copy and restores the link. |

A daily takedown check, enabled by default, checks each stored copy on an allowed host. It removes a stored copy only when the server returns `404` or `410` and leaves a link in its place. Timeouts and server errors leave stored copies unchanged. Image artifacts render as `<img>` elements pointing to the image URL in the embed document without copying image files to the site.

| Plugin | Requirements | Stored embeds | Live embeds | Background fetch |
|---|---|---|---|---|
| WordPress | WordPress 6.5+, PHP 8.1+ | Framework artifact block | URL on its own line, `[framework_artifact url="…"]` | Action Scheduler when installed, otherwise WP-Cron |
| Drupal | Drupal 10.3+ or 11, PHP 8.1+ | Framework artifact field | Text format filter, Link field formatter | Post-response callback, with a cron queue fallback |
| Statamic | Statamic 5 or 6, PHP 8.1+ | `framework_artifact` fieldtype and entry actions | `{{ framework:artifact url="…" }}`, `framework_embeds` modifier | Post-response job or Laravel queue |

Each plugin validates allowed hosts with `UrlMatcher` following the rules in `SPEC.md`.

### WordPress

Download `framework-embed-<version>.zip` from the [latest release](https://github.com/frameworktools/framework-embed/releases/latest), or build it from source:

```sh
make wordpress   # writes wordpress/dist/framework-embed-<version>.zip
```

Upload the archive under **Plugins → Add New → Upload Plugin** and activate it. The archive bundles `php/src` and `php/assets` with its own autoloader, so WordPress requires no Composer step. Add self-hosted Framework Cloud servers under **Settings → Framework embeds**. Live embeds require WP-Cron or a system cron job calling `wp-cron.php`. See `wordpress/readme.txt` for details.

### Drupal

```sh
composer require drupal/framework_embed
drush en framework_embed
```

Add a **Framework artifact** field to a content type for stored embeds. For live embeds, enable **Embed Framework artifacts** on a text format after **Limit allowed HTML tags**, or select the **Framework artifact** formatter on a Link field. Until the packages are published on Packagist, configure path repositories as shown in `drupal/README.md`.

### Statamic

```sh
composer require framework-cloud/statamic-embed
php artisan vendor:publish --tag=framework-embed
```

Add `{{ framework:styles }}` inside `<head>` and `{{ framework:scripts only_if_charts="true" }}` before `</body>`. Add a **Framework artifact** field to a blueprint for stored embeds and render it with `{{ framework:artifact field="handle" }}`. Scheduled takedown checks require the Laravel scheduler. Until the packages are published on Packagist, configure path repositories as shown in `statamic/README.md`.

## Web component and JavaScript

Include the stylesheet and browser bundle to use `<framework-artifact>` on any HTML page:

```html
<link rel="stylesheet" href="framework-embed.css">
<script src="framework-embed.iife.js" defer></script>

<framework-artifact src="https://framework.pub/@ada/admissions/model">
  <a href="https://framework.pub/@ada/admissions/model">Admission model</a>
</framework-artifact>
```

The custom element renders into light DOM so host page styles apply. Child elements remain visible as a fallback until the embed document loads. Placing a `framework.embed/v1` document inside a child `<script type="application/json">` renders without a network request. Set `window.FrameworkEmbedConfig = {hosts: ["framework.pub", "framework.example.edu"]}` before the script tag to allow additional servers.

Programmatic usage through `@framework-cloud/embed`:

```js
import { render, fetchEmbed, mount } from '@framework-cloud/embed';

const doc = await fetchEmbed('https://framework.pub/d/aB3dE5fG7hJ9kL1m'); // null on 404 or 410
const html = render(doc); // escaped HTML string
mount(document.querySelector('#figure'), doc); // render HTML and initialize charts
```

See `js/index.d.ts` and `js/README.md` for the full JavaScript API.

For the logistic regression in `spec/fixtures/r-glm-logit.json`, the rendered coefficient table contains:

| Term | Estimate | Odds ratio (95% CI) | Std. error | Statistic | p-value | 95% CI |
|---|---|---|---|---|---|---|
| (Intercept) | 18.87 | — | 7.44 | 2.53 | 0.011 | 4.28 to 33.46 |
| wt | -8.08 | < 0.01 (< 0.01 to 0.13) | 3.07 | -2.63 | 0.008 | -14.10 to -2.07 |
| hp | 0.04 | 1.04 (1.00 to 1.07) | 0.02 | 2.04 | 0.041 | < 0.01 to 0.07 |

Fit statistics appear in a collapsed **Model details** panel below the table. Model and test results use two decimal places, data tables use three decimal places, and values never use scientific notation.

Numeric cells use JetBrains Mono (`@fontsource/jetbrains-mono` 5.2.8 from jsDelivr, OFL-1.1), while labels and prose inherit the site's font. Set `--fw-embed-mono` to use another monospace font and skip the font download:

```css
.fw-embed { --fw-embed-mono: ui-monospace, Menlo, monospace; }
```

## PHP

```php
use Framework\Embed\Client;
use Framework\Embed\Renderer;

$client = new Client(hosts: ['framework.pub'], cache: $cache);
$doc = $client->resolve('https://framework.pub/@ada/admissions/model'); // null on 404 or 410
echo $doc === null ? '' : Renderer::render($doc);
```

`Client` accepts any fetcher callable and any cache implementing `Framework\Embed\Cache`. `Psr16Cache` adapts a PSR-16 cache store. `Assets::css()` and `Assets::script()` return filesystem paths to the bundled stylesheet and script.

## Data flow

1. An author pastes or enters an artifact URL such as `https://framework.pub/@ada/admissions/model`.
2. The consumer checks the URL host against its allowed hosts and requests `/api/embed/v1/resolve?url=…` from that server.
3. The server responds with a `framework.embed/v1` JSON document, or `404` when the artifact is private, password-protected, or deleted.
4. The renderer turns the document into `<figure class="fw-embed">`. PHP sites render on the server, and static pages can render in the browser with `<framework-artifact>`.
5. `framework-embed.css` styles the output. When a Vega-Lite chart is present on the page, `framework-embed.iife.js` loads `vega-embed` and renders the chart.

## Repository layout

| Path | Contents |
|---|---|
| `SPEC.md` | `framework.embed/v1` specification covering document schema, markup, number formatting, allowed hosts, and theming |
| `spec/embed.schema.json` | JSON Schema for embed documents |
| `spec/fixtures/*.json` | Fixture embed documents from Framework Cloud and edge-case test inputs |
| `spec/fixtures/*.html` | Expected HTML output that both renderers must match byte for byte |
| `spec/format-cases.json` | Shared number-formatting test cases |
| `spec/model-names.json` | Shared model-naming test cases |
| `js/` | `@framework-cloud/embed` package: renderer, `<framework-artifact>` element, chart loader, and stylesheet |
| `php/` | `framework-cloud/embed` package: client, URL matcher, renderer, and bundled CSS and JS assets |
| `wordpress/` | WordPress plugin `framework-embed` |
| `drupal/` | Drupal module `framework_embed` |
| `statamic/` | Statamic addon `framework-cloud/statamic-embed` |
| `testing/` | `FixtureServer.php` test fetcher shared across plugin test suites |

The JavaScript and PHP renderers produce identical HTML for every fixture.

## Development

```sh
make install           # npm ci in js/, composer install in php/
make build             # build js/dist and copy assets into php/assets and drupal/assets
make test              # run Vitest, PHPUnit, and asset sync checks
make fixtures          # regenerate spec/fixtures/*.html and spec/format-cases.json from JS

make statamic-install  # composer install in statamic/
make plugins-test      # run WordPress, Drupal, and Statamic unit tests
make wordpress-e2e     # run WordPress Playground end-to-end checks
make drupal-e2e DRUPAL_PROJECT=/path/to/drupal-project
```

`make fixtures` rewrites expected HTML fixtures from the JavaScript renderer. Review every diff before committing.

Packagist requires `composer.json` at the repository root, so publishing `framework-cloud/embed` requires either a split repository or a root `composer.json` pointing to `php/`.
