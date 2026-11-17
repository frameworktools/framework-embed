# framework-embed

Renderers that show Framework artifacts on any website without iframes. A Framework Cloud server, such as framework.pub, serves an embed document for each public or unlisted artifact. The renderers here turn that document into static, escaped HTML that the site's own CSS can theme.

Supported artifacts:

- regression models: one coefficient table with estimates, odds, hazard, or rate ratios when the model has them, standard errors, statistics, p-values, and confidence intervals. Fit statistics and other tables sit in a closed "Model details" disclosure;
- tables, with the first 25 rows;
- hypothesis tests: the statistic, p-value, and estimate with its interval, with the rest under "Test details";
- figures (SVG, PNG, JPEG, GIF, and WebP);
- Vega-Lite charts;
- everything else as a link card.

The code is MIT licensed. No account or key is needed to embed a public or unlisted artifact.

## Repository layout

| Path | Contents |
|---|---|
| `SPEC.md` | The `framework.embed/v1` specification: document, markup, number formatting, allowed hosts, and theming |
| `spec/embed.schema.json` | JSON Schema for embed documents. It allows unknown fields. |
| `spec/fixtures/*.json` | Embed documents. Six come from Framework Cloud's own test fixtures. The rest are made by hand for other artifact types and edge cases. |
| `spec/fixtures/*.html` | The exact HTML every renderer must produce for each document |
| `spec/format-cases.json` | Number formatting cases every renderer must reproduce |
| `js/` | `@framework-cloud/embed`: renderer, `<framework-artifact>` element, chart loader, and stylesheet |
| `php/` | `framework-cloud/embed`: client, URL matcher, renderer, and a copy of the built CSS and JS |
| `wordpress/` | WordPress plugin `framework-embed` |
| `drupal/` | Drupal module `framework_embed` |
| `statamic/` | Statamic addon `framework-cloud/statamic-embed` |
| `testing/` | `FixtureServer.php`, a test-only fetcher that serves `spec/fixtures`, shared by the plugin test harnesses |

The JS and PHP renderers produce byte-identical HTML for every fixture. The CMS plugins render through the PHP package.

## Data flow

1. A site author pastes an artifact URL, such as `https://framework.pub/@ada/admissions/model`.
2. The consumer checks the URL's host against its allowed hosts and requests `/api/embed/v1/resolve?url=…` from that host.
3. The server answers with a `framework.embed/v1` document, or `404` when the artifact is private, password-protected, or gone.
4. A renderer turns the document into a `<figure class="fw-embed">`. A PHP site does this on the server. A static site can use the `<framework-artifact>` element.
5. `framework-embed.css` styles the figure. `framework-embed.iife.js` draws Vega-Lite charts with vega-embed, which it loads only when a chart is on the page.

## JavaScript

```html
<link rel="stylesheet" href="framework-embed.css">
<script src="framework-embed.iife.js" defer></script>

<framework-artifact src="https://framework.pub/@ada/admissions/model">
  <a href="https://framework.pub/@ada/admissions/model">Admission model</a>
</framework-artifact>
```

The element renders into light DOM, so site CSS applies. Its children remain as the fallback until the document loads. A stored document in a child `<script type="application/json">` renders without a request. Set `window.FrameworkEmbedConfig = {hosts: ["framework.pub", "framework.example.edu"]}` before the script to allow another server.

```js
import { render, fetchEmbed, mount } from '@framework-cloud/embed';

const doc = await fetchEmbed('https://framework.pub/d/aB3dE5fG7hJ9kL1m'); // null when gone
const html = render(doc); // pure, escaped HTML string
mount(document.querySelector('#figure'), doc); // render and draw charts
```

See `js/index.d.ts` for the full API.

For the logistic regression in `spec/fixtures/r-glm-logit.json`, the coefficient table reads:

| Term | Estimate | Odds ratio (95% CI) | Std. error | Statistic | p-value | 95% CI |
|---|---|---|---|---|---|---|
| (Intercept) | 18.87 | — | 7.44 | 2.53 | 0.011 | 4.28 to 33.46 |
| wt | -8.08 | < 0.01 (< 0.01 to 0.13) | 3.07 | -2.63 | 0.008 | -14.10 to -2.07 |
| hp | 0.04 | 1.04 (1.00 to 1.07) | 0.02 | 2.04 | 0.041 | < 0.01 to 0.07 |

The fit statistics sit in a closed "Model details" disclosure below it.

Model and test results use two decimal places. Data tables use three. Nothing uses scientific notation. `SPEC.md` defines both rules.

Numbers are set in JetBrains Mono (`@fontsource/jetbrains-mono` 5.2.8 from jsDelivr, OFL-1.1). Labels and prose keep the site's font. To use another font for numbers, and skip the download, set the custom property:

```css
.fw-embed { --fw-embed-mono: ui-monospace, Menlo, monospace; }
```

## PHP

```php
use Framework\Embed\Client;
use Framework\Embed\Renderer;

$client = new Client(hosts: ['framework.pub'], cache: $cache);
$doc = $client->resolve('https://framework.pub/@ada/admissions/model'); // null when gone
echo $doc === null ? '' : Renderer::render($doc);
```

`Client` takes any fetcher callable and any cache that implements `Framework\Embed\Cache` (a PSR-16 subset; `Psr16Cache` wraps a PSR-16 cache). `Assets::css()` and `Assets::script()` give the paths of the shipped stylesheet and script.

## CMS plugins

Each plugin renders through the PHP package, contacts only framework.pub and hosts an administrator adds, and never waits on the network while rendering a page. Embeds come in two tiers.

| Tier | Used by | Behavior |
|---|---|---|
| Stored (preferred) | WordPress block, Drupal field, Statamic fieldtype | Inserting a URL fetches the embed document once and saves it with the content, pinned to its version URL. Pages render from that copy. "Fetch again" (same version) and "Use newest version" replace it only after the author confirms. |
| Live | Pasted URLs, shortcodes, text filters, Link formatters, Antlers `url=` tag and modifier | The first view renders a link and queues a background fetch. Later views render from a cache that honors `Cache-Control`. An expired copy renders while it refreshes. A `404` drops the copy and the link stays. |

A daily takedown check, on by default and switchable, asks the server about each stored copy on an allowed host. It removes a copy only on a `404` or `410`, never on a timeout or server error, and leaves a link in its place. Image artifacts render as `<img>` pointing at the image URL in the document; no file is copied.

| Plugin | Requires | Stored | Live | Background fetch |
|---|---|---|---|---|
| WordPress | WordPress 6.5+, PHP 8.1+ | Framework artifact block (document in a block attribute) | Paste on its own line, `[framework_artifact url="…"]` | Action Scheduler if installed, else WP-Cron |
| Drupal | Drupal 10.3+ or 11, PHP 8.1+ | Framework artifact field | Text format filter, Link field formatter | After the response, with a cron queue as backup |
| Statamic | Statamic 5 or 6, PHP 8.1+ | `framework_artifact` fieldtype, with entry actions | `{{ framework:artifact url="…" }}`, `framework_embeds` modifier | After the response, or the queue |

Allowed hosts follow the rules in "Allowed hosts" in `SPEC.md`. Each plugin validates them with `UrlMatcher`.

### WordPress

```sh
make wordpress   # writes wordpress/dist/framework-embed-<version>.zip
```

Upload the zip under Plugins → Add New → Upload Plugin and activate it. The zip bundles `php/src` and `php/assets` with its own autoloader, so the site needs no Composer. To allow a self-hosted server, add its host under Settings → Framework embeds. Live embeds need WP-Cron, or a real cron job calling `wp-cron.php`. See `wordpress/readme.txt`.

### Drupal

```sh
composer require drupal/framework_embed
drush en framework_embed
```

Add a "Framework artifact" field to a content type for stored embeds. For live embeds, enable "Embed Framework artifacts" on a text format, after "Limit allowed HTML tags", or choose the "Framework artifact" formatter for Link fields. Until the packages are published, install from path repositories as `drupal/README.md` describes.

### Statamic

```sh
composer require framework-cloud/statamic-embed
php artisan vendor:publish --tag=framework-embed
```

Add `{{ framework:styles }}` to the layout's `<head>` and `{{ framework:scripts only_if_charts="true" }}` before `</body>`. Add a "Framework artifact" field to a blueprint for stored embeds and render it with `{{ framework:artifact field="handle" }}`. The takedown check needs Laravel's scheduler. Until the packages are published, install from path repositories as `statamic/README.md` describes.

## Development

```sh
make install   # npm ci in js/, composer install in php/
make build     # build js/dist and copy it into php/assets
make test      # vitest, PHPUnit, and a check that php/assets matches js/dist
make fixtures  # regenerate spec/fixtures/*.html and spec/format-cases.json from the JS renderer

make statamic-install  # composer install in statamic/
make plugins-test      # WordPress, Drupal, and Statamic unit tests
make wordpress-e2e     # WordPress in Playground, with fixture fetches
make drupal-e2e DRUPAL_PROJECT=/path/to/drupal-project
```

`make fixtures` rewrites the expected output. Review every diff by hand before committing it.

Packagist needs `composer.json` at a repository root. Publishing the PHP package will need a split repository or a root `composer.json` that points at `php/`.
