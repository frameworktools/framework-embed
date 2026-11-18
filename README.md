# framework-embed

`framework-embed` displays published Framework artifacts on blogs, personal sites, and web pages. It includes plugins for WordPress, Drupal, and Statamic, a `<framework-artifact>` custom element for HTML pages, and JavaScript and PHP renderers.

Each public or unlisted artifact on [framework.pub](https://framework.pub) or a self-hosted Framework Cloud server provides a `framework.embed/v1` document. The renderers convert that document into escaped HTML styled with CSS custom properties rather than rendering an iframe. Embedding requires no account or API key on the receiving site, and the code is MIT licensed.

## Supported artifacts

- **Regression models.** Coefficient tables with estimates, standard errors, test statistics, p-values, and confidence intervals, with a switch to odds, hazard, or rate ratios when applicable. Terms show the variable behind them in a tip when the model was published with its variables. Fit statistics and additional tables appear in a collapsible **Model details** panel.
- **Tables.** Static HTML tables displaying the first 25 rows with a link to the full table.
- **Hypothesis tests.** Test statistic, p-value, and estimate with its confidence interval, with additional parameters under **Test details**.
- **Code snippets.** R, Python, or Stata code with highlighting, and the output, messages, warnings, and errors it printed. Long output folds. The JavaScript bundle adds buttons to copy the code and show or hide the output. `?output=none` on the artifact URL starts with the output hidden.
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

Neither the module nor `framework-cloud/embed` is on Packagist or drupal.org. Clone this repository at a release tag, then register both directories as Composer path repositories in the site's root `composer.json`:

```sh
git clone --branch v0.5.0 https://github.com/frameworktools/framework-embed.git /path/to/framework-embed
composer config repositories.framework-embed-php '{"type":"path","url":"/path/to/framework-embed/php","options":{"versions":{"framework-cloud/embed":"0.1.0"}}}'
composer config repositories.framework-embed-drupal '{"type":"path","url":"/path/to/framework-embed/drupal","options":{"versions":{"drupal/framework_embed":"0.1.0"}}}'
composer require drupal/framework_embed:0.1.0 framework-cloud/embed:0.1.0
drush en framework_embed
```

Keep `0.1.0` in these commands to satisfy the `^0.1` constraint in the module's `composer.json`. The installed code comes from the cloned tag.

Add a **Framework artifact** field to a content type for stored embeds. For live embeds, enable **Embed Framework artifacts** on a text format after **Limit allowed HTML tags**, or select the **Framework artifact** formatter on a Link field. See `drupal/README.md`.

### Statamic

The addon and `framework-cloud/embed` are not on Packagist. Clone this repository at a release tag and register both directories as path repositories:

```sh
git clone --branch v0.5.0 https://github.com/frameworktools/framework-embed.git /path/to/framework-embed
composer config repositories.framework-embed-php '{"type":"path","url":"/path/to/framework-embed/php","options":{"versions":{"framework-cloud/embed":"0.1.0"}}}'
composer config repositories.framework-embed-statamic '{"type":"path","url":"/path/to/framework-embed/statamic","options":{"versions":{"framework-cloud/statamic-embed":"0.1.0"}}}'
composer require framework-cloud/statamic-embed:0.1.0 framework-cloud/embed:0.1.0
php artisan vendor:publish --tag=framework-embed
```

Keep `0.1.0` in these commands to satisfy the `^0.1` constraint in the addon's `composer.json`. The installed code comes from the cloned tag.

Add `{{ framework:styles }}` inside `<head>` and `{{ framework:scripts only_if_charts="true" }}` before `</body>`. Add a **Framework artifact** field to a blueprint for stored embeds and render it with `{{ framework:artifact field="handle" }}`. Scheduled takedown checks require the Laravel scheduler. See `statamic/README.md`.

## Web component and JavaScript

The JavaScript package is not on npm. Each [release](https://github.com/frameworktools/framework-embed/releases) attaches these files, which are also committed under `php/assets/`:

| File | Contents |
|---|---|
| `framework-embed.css` | Stylesheet |
| `framework-embed.iife.js` | Script-tag build. It sets `window.FrameworkEmbed`, applies `window.FrameworkEmbedConfig`, registers `<framework-artifact>`, prepares disclosures for printing, and draws charts and sets up variable tips, copy buttons, and output toggles on load. |
| `framework-embed.js` | ES module with the same exports. Importing it registers no custom element and adds no event listeners. |

Host the files with your site, or load them from jsDelivr at a pinned tag, such as `https://cdn.jsdelivr.net/gh/frameworktools/framework-embed@v0.5.0/php/assets/framework-embed.iife.js`. GitHub Pages serves the latest release under unversioned names at `https://frameworktools.github.io/framework-embed/`.

Include the stylesheet and script-tag build to use `<framework-artifact>` on any HTML page:

```html
<link rel="stylesheet" href="framework-embed.css">
<script src="framework-embed.iife.js" defer></script>

<framework-artifact src="https://framework.pub/@ada/admissions/model">
  <a href="https://framework.pub/@ada/admissions/model">Admission model</a>
</framework-artifact>
```

The custom element renders into light DOM so host page styles apply. Child elements remain visible as a fallback until the embed document loads. Placing a `framework.embed/v1` document inside a child `<script type="application/json">` renders without a network request. Set `window.FrameworkEmbedConfig = {hosts: ["framework.pub", "framework.example.org"]}` before the script tag to allow additional servers. The list replaces the default, so keep `framework.pub` in it.

Pages that import the ES module build call the setup functions themselves:

```js
import { configure, expandDetailsForPrint, fetchEmbed, mount, render } from './framework-embed.js';

configure({ hosts: ['framework.pub'] });
expandDetailsForPrint();

const doc = await fetchEmbed('https://framework.pub/d/aB3dE5fG7hJ9kL1m'); // null on 404 or 410
const html = render(doc); // escaped HTML string, for server rendering or a build step
mount(document.querySelector('#figure'), doc); // insert the HTML into an attached element, then draw charts and set up tips and snippet buttons
```

After inserting `render()` output any other way, call `enhanceCharts(root)`, `enhanceTips(root)`, `enhanceCopy(root)`, and `enhanceOutput(root)` on the element that contains it, once that element is in the document. See `js/README.md` for the full JavaScript API.

For the logistic regression in `spec/fixtures/r-glm-logit.json`, the rendered coefficient table opens on the odds ratio scale, with a switch to log odds:

| Term | Odds ratio | Std. error | Statistic | p-value | 95% CI |
|---|---|---|---|---|---|
| (Intercept) | 156,145,483.51 | 1,162,277,972.32 | 2.53 | 0.011 | 72.04 to 338,452,350,596,257.20 |
| wt | 0.00 | 0.00 | -2.63 | 0.008 | 0.00 to 0.13 |
| hp | 1.04 | 0.02 | 2.04 | 0.041 | 1.00 to 1.07 |

Fit statistics appear in a collapsed **Model details** panel below the table. Model and test results use two decimal places, data tables use three decimal places, and values never use scientific notation. A rounded value carries its full value in a `title` attribute.

Numeric cells use JetBrains Mono (`@fontsource/jetbrains-mono` 5.2.8 from jsDelivr, OFL-1.1), while labels and prose inherit the site's font. Set `--fw-embed-mono` to use another monospace font and skip the font download:

```css
.fw-embed { --fw-embed-mono: ui-monospace, Menlo, monospace; }
```

## PHP

The PHP package is not on Packagist. Install it with Composer from a release archive by declaring it as a package repository:

```json
{
    "repositories": [
        {
            "type": "package",
            "package": {
                "name": "framework-cloud/embed",
                "version": "0.5.0",
                "dist": {"type": "zip", "url": "https://github.com/frameworktools/framework-embed/archive/refs/tags/v0.5.0.zip"},
                "autoload": {"psr-4": {"Framework\\Embed\\": "php/src/"}}
            }
        }
    ],
    "require": {"framework-cloud/embed": "0.5.0"}
}
```

A clone used as a path repository, as in the Drupal and Statamic instructions, also works. Without Composer, copy the `php/src` and `php/assets` directories side by side and autoload the `Framework\Embed` namespace from `src`.

```php
use Framework\Embed\Client;
use Framework\Embed\Renderer;

$client = new Client(hosts: ['framework.pub'], cache: $cache);
$doc = $client->resolve('https://framework.pub/@ada/admissions/model'); // null on 404 or 410
echo $doc === null ? '' : Renderer::render($doc);
```

`Client` accepts any fetcher callable and any cache implementing `Framework\Embed\Cache`. `Psr16Cache` adapts a PSR-16 cache store. `Assets::css()`, `Assets::script()`, and `Assets::module()` return filesystem paths to the bundled stylesheet and scripts.

## Custom integrations

`SPEC.md` is the full contract: the API, the embed document, the markup each renderer produces, number formatting, chart safety, allowed hosts, and theming. `spec/embed.schema.json` validates embed documents. The [embed integrations guide](https://framework.pub/docs/embed-integrations) covers the API, markup, page setup, HTML sanitizers, Content Security Policy, caching, and conformance tests. The [artifact representation reference](https://framework.pub/docs/artifact-representation) describes the `data` field.

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
| `js/` | JavaScript renderer, `<framework-artifact>` element, chart loader, and stylesheet (`@framework-cloud/embed`, not published to npm) |
| `php/` | PHP client, URL matcher, renderer, and bundled CSS and JS assets (`framework-cloud/embed`, not published to Packagist) |
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
