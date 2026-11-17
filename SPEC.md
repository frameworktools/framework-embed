# framework.embed/v1 specification

This is the public specification for showing Framework artifacts on other sites. Framework Cloud serves embed documents. Renderers in this repository turn them into HTML.

The sections "Embed document", "Renderer markup", and "Security rules for consumers" are copied from Framework Cloud's plan (`docs/plans/2026-11-16-artifact-embeds.md` in the Cloud repository). Keep them in sync with it. The sections after them define what the contract leaves open. Both renderers implement them, and the fixtures in `spec/fixtures` test them.

## Embed API

A Framework Cloud server answers these public, cookie-free routes:

| Route | Answer |
|---|---|
| `GET /api/embed/v1/resolve?url=<artifact URL>` | The embed document for a pasted artifact URL on that server. |
| `GET /api/embed/v1/artifacts/{key}` | The embed document for the newest version. |
| `GET /api/embed/v1/artifacts/{key}/versions/{n}` | The embed document for version `n`. |
| `GET /api/embed/v1/artifacts/{key}/versions/{n}/image` | The figure file for image artifacts. It may redirect to a signed file URL. |
| `GET /api/oembed?url=…&format=json` | oEmbed 1.0: `photo` for images, `link` for everything else. |
| `GET /.well-known/framework.json` | Discovery of the routes above. |

Private, password-protected, missing, and deleted artifacts all answer `404` with `Cache-Control: no-store`. Version URLs send `Cache-Control: public, max-age=3600`. Newest-version and resolve URLs send `public, max-age=300`.

## Embed document: `framework.embed/v1`

```json
{
  "schema": "framework.embed/v1",
  "key": "aB3dE5fG7hJ9kL1m",
  "version": 3,
  "latest_version": 3,
  "kind": "model",
  "title": "Admission model",
  "description": null,
  "alt": "Logistic regression of admitted with 4 terms. Odds ratios available.",
  "url": "https://framework.pub/d/aB3dE5fG7hJ9kL1m/versions/3",
  "embed_url": "https://framework.pub/api/embed/v1/artifacts/aB3dE5fG7hJ9kL1m/versions/3",
  "published_at": "2026-11-16T12:00:00Z",
  "author": {"name": "Ada Example", "url": "https://framework.pub/@ada"},
  "provider": {"name": "Framework", "url": "https://framework.pub"},
  "attribution": {"text": "Framework", "url": "https://framework.pub/d/aB3dE5fG7hJ9kL1m/versions/3"},
  "data": { "...": "the framework.artifact/v1 representation, as Cloud normalizes it" },
  "spec": null,
  "image": null
}
```

- `kind` is one of `model`, `table`, `test`, `image`, `vega-lite`, `page`. Renderers treat an unknown kind as `page`.
- `data` is set for `model`, `table`, and `test`: the normalized representation from `ArtifactRepresentation::parse` (tables capped at 10, rows at 1,000, columns at 50, with `truncated`, `row_count`, `column_count`, `precision_note`).
- `spec` is set for `vega-lite`: the Vega-Lite specification, data inlined.
- `image` is `{"url", "content_type", "width", "height"}`. It is set for `image`, and it may be set for other kinds later as a fallback. `width` and `height` may be null.
- `page` covers HTML documents, Quarto sites, PDFs, data files, and anything else. Renderers show a link card: title, description, author, and the attribution link.
- `author.url` and `description` may be null. Fields may be added within `v1`; renderers ignore fields they don't know.
- `alt` is a one-sentence text description for screen readers and image alt text.

## Renderer markup

The JS and PHP renderers produce the same HTML for the same embed document; the shared fixtures in `framework-embed/spec/fixtures` hold the expected output for both. All artifact text is inserted as text, never as HTML. Every class starts with `fw-embed`.

```html
<figure class="fw-embed fw-embed--model" data-fw-embed-kind="model" data-fw-embed-key="…" data-fw-embed-version="3">
  <div class="fw-embed__body">…kind-specific content…</div>
  <figcaption class="fw-embed__caption">
    <span class="fw-embed__title">Admission model</span>
    <a class="fw-embed__credit" href="…attribution.url…" target="_blank" rel="noopener">Framework</a>
  </figcaption>
</figure>
```

- `model`: a coefficient table per coefficient-like table (estimate, standard error, confidence interval, p-value when present), the exponentiated column when `model.exponentiate` is set, then fit statistics as a `<dl>`.
- `table`: a static `<table>` with the first 25 rows and a note giving the total and a link to the artifact for the rest.
- `test`: method, statistic, parameters, p-value, estimates, and confidence interval.
- `image`: `<img src alt loading="lazy">`.
- `vega-lite`: a `<div class="fw-embed__chart" data-fw-embed-chart>` holding the spec in `<script type="application/json">`, and a link to the artifact as the fallback. The JS bundle draws it with vega-embed; without JavaScript the link stays.
- `page`: a link card.

The stylesheet themes through custom properties (`--fw-embed-font`, `--fw-embed-mono`, `--fw-embed-fg`, `--fw-embed-muted`, `--fw-embed-bg`, `--fw-embed-border`, `--fw-embed-accent`). It follows `prefers-color-scheme` and a `data-theme="dark"` or `.dark` ancestor.

## Security rules for consumers

- Fetch only from hosts the site trusts: framework.pub by default, plus servers an administrator adds. Never fetch a host taken from a pasted URL without that check.
- Insert artifact text as text. Show SVG only through `<img>`.
- Cache embed documents for no longer than the `Cache-Control` they came with, and drop the copy when the server answers `404`.
- Load vega-embed from the site itself or a pinned CDN version.

## Consumer tiers

Consumers use embed documents in one of two ways.

### Stored

A consumer may store an embed document with its own content indefinitely and render from the stored copy. A CMS block, a static site build, and an academic.page page are examples. It re-fetches only when the author asks, for example with a refresh button.

- A `framework.embed/v1` document must render forever. Renderers never drop support for a v1 shape. New fields are optional and old documents stay valid.
- The `<framework-artifact>` element renders a stored document from a direct child `<script type="application/json">` without fetching. The `src` attribute is then used only when the inline document is missing or is not `framework.embed/v1`.

```html
<framework-artifact src="https://framework.pub/d/aB3dE5fG7hJ9kL1m/versions/3">
  <script type="application/json">{"schema":"framework.embed/v1", …}</script>
</framework-artifact>
```

The stored document belongs inside a `<script type="application/json">` element written with the same escaping as chart specifications (see `vega-lite` below).

### Live

A consumer may fetch the document each time it renders.

- It caches the document no longer than its `Cache-Control` allows.
- Fetching never blocks rendering. The JS element keeps its children as the fallback until the document arrives. A server-side consumer renders its cached copy or a plain link when the fetch fails.
- A `404` or `410` drops the cached copy, and the consumer stops showing the artifact.

### Trimmed tables

Cloud trims the rows of each table in an embed document to what renderers show: the first 25. `row_count` keeps the true total, and the note reads `Showing 25 of {row_count} rows.` A table with fewer rows than `row_count` renders the rows it has. Decimal formatting depends only on the shown rows, so a trimmed document renders exactly as the untrimmed one does.

## Markup by kind

Every renderer output is one `<figure>` followed by a newline. Block-level elements are separated by single newlines. Attribute values and text are escaped with `&amp;`, `&lt;`, `&gt;`, `&quot;`, and `&#39;`. Nothing else is escaped.

The `<figure>` carries `data-fw-embed-key` when `key` is a non-empty string and `data-fw-embed-version` when `version` is an integer. Its kind class and `data-fw-embed-kind` name the kind that was rendered. A document whose kind-specific content is missing or unusable renders as `page`.

### Caption

`<figcaption class="fw-embed__caption">` holds, in order:

- `fw-embed__title`: `title`, when present. Left out for `page`.
- `fw-embed__description`: `description`, when present. Left out for `page`.
- `fw-embed__author`: `author.name`, linked to `author.url` when that URL is safe. Left out for `page`.
- `fw-embed__credit`: `attribution.text` (default `Framework`), linked to `attribution.url`, or to `url` when that is missing or unsafe, with `target="_blank" rel="noopener"`. A `<span>` when neither URL is safe.

### `model`

A model shows its estimates. Everything else goes in a closed `<details class="fw-embed__details">` whose `<summary>` reads `Model details`. Readers can open it without JavaScript.

Inside `fw-embed__body`, in order:

1. `<p class="fw-embed__meta">`: the model type, family and link, and response, joined by ` · `. For example, `Generalized linear model · binomial family, logit link · Response: am`. Type labels: `linear` Linear model, `glm` Generalized linear model, `mixed` Mixed model, `survival` Survival model, `ordinal` Ordinal model, `multinomial` Multinomial model, `additive` Additive model, `fixed_effects` Fixed-effects model, `quantile` Quantile regression, `linear_ml` Linear model, `tree_ensemble` Tree ensemble. `other` is left out. Unknown types appear as sent.
2. `<p class="fw-embed__formula"><code>`: `formula`, when present.
3. An estimates table (`fw-embed__table--estimates`) for each coefficient table.
4. The `Model details` disclosure, holding:
   - the full coefficient table (`fw-embed__table--coefficients`) for each coefficient table;
   - every other table (random effects, importance, smooth terms, ANOVA, and others) as a data table with model column labels, up to 25 rows;
   - `<dl class="fw-embed__stats">` with the metrics.

A table is a coefficient table when its `role` is `coefficients` (or it is the first table and has no role) and it has `term` and `estimate` columns.

A model with no coefficient table but with an `importance` table shows that table's first 10 rows in place of item 3. Its note reads `Showing 10 of {row_count} rows. More in Model details.` The full table is also in the disclosure.

A model with no coefficient or importance table shows its tables and metrics without a disclosure.

Both coefficient tables start with Term, a row header (`<th scope="row">`). Next come any columns outside the standard set (`term`, `estimate`, `std_error`, `statistic`, `p_value`, `conf_low`, `conf_high`, `df`). The remaining columns depend on the table. Columns whose data is absent are left out.

- **Estimates table, without `model.exponentiate`:** Estimate, and the interval as `low to high` under `95% CI`.
- **Estimates table, with `model.exponentiate`:** one column for the exponentiated estimate with its exponentiated interval. The header is the label with the interval, such as `Odds ratio (95% CI)`. The cell reads `exp(estimate) (exp(low) to exp(high))`, such as `1.04 (1.00 to 1.07)`. Without interval columns, the header is the label alone and the cell is `exp(estimate)`. The model-scale estimate is not shown. Intercept rows show `—`. A term is an intercept when, in ASCII lower case, it is `(intercept)`, `intercept`, `const`, or `_cons`.
- **Full coefficient table:** Estimate, Std. error, Statistic, p-value, and the interval on the model's scale.

The interval header takes its level from the `confidence_level` metric. Without that metric, the header is `CI`. Estimates, ratios, standard errors, statistics, and bounds use result formatting with forced decimals. The ratio cell for the glm fixture's `wt` reads `< 0.01 (< 0.01 to 0.13)`.

Statistic values (`<dd>`) carry `class="fw-embed__num"` when the value is a number: metric numbers and percents, and every test value except `Alternative`.

`exp` is fdlibm's `exp`, computed with IEEE basic operations only, so the JS and PHP renderers agree bit for bit. Platform `exp` functions can differ in the last place.

Metric labels: `nobs` Observations, `df_resid` Residual df, `df_model` Model df, `aic` AIC, `bic` BIC, `rsquared` and `r.squared` R², `rsquared_adj` and `adj.r.squared` Adjusted R², `pseudo_rsquared` Pseudo R², `sigma` Residual std. dev., `llf` and `log_likelihood` Log likelihood, `deviance` Deviance, `null.deviance` Null deviance, `dispersion` Dispersion, `converged` Converged, `reml` REML, `confidence_level` Confidence level, `interval_method` Interval method, `vcov_type` Standard errors, `n_estimators` Trees, `max_depth` Maximum depth, `learning_rate` Learning rate, `n_features` Features, `importance_type` Importance type, `objective` Objective, `n_events` Events, `concordance` Concordance, `theta` Theta, `command` Command. `n_groups.<factor>` becomes `Groups: <factor>`. Other names replace `_` and `.` with spaces and capitalize an initial ASCII letter.

Metric values: `confidence_level` as a percent, `interval_method` `wald-t` as Wald t, `wald-normal` as Wald normal, `profile` as Profile likelihood, and `bootstrap` as Bootstrap. p-value names use p-value formatting. Other numbers use result formatting.

### `table`

Each table renders as a data table. Captions appear only when there is more than one table. Column headers use `label`, else `name`.

### Data tables

```html
<div class="fw-embed__scroll" role="region" tabindex="0" aria-label="{title}">
<table class="fw-embed__table">
<caption>{title}</caption>
<thead><tr><th scope="col">…</th></tr></thead>
<tbody>
<tr><td>…</td></tr>
</tbody>
</table>
</div>
<p class="fw-embed__note">Showing <span class="fw-embed__num">25</span> of <span class="fw-embed__num">60</span> rows. <a href="{url}">View the full table</a></p>
<p class="fw-embed__note">{precision_note}</p>
```

- The first 25 rows are shown.
- Number columns get `class="fw-embed__num"` on their header and cells.
- A number column is decimal when any shown value in it is not a whole number. Every value in a decimal column gets the full number of decimals: three in `table` documents (data formatting), two in model and test tables (result formatting).
- Model and test tables label known columns: `term` Term, `estimate` Estimate, `std_error` Std. error, `statistic` Statistic, `p_value` p-value, `conf_low` CI lower, `conf_high` CI upper, `group` Group, `variance` Variance, `std_dev` Std. dev., `correlation` Correlation, `feature` Feature, `importance` Importance, `edf` EDF, `ref_df` Ref. df. A column `label` wins over these.
- The note appears when `row_count` exceeds the rows shown or `column_count` exceeds the columns sent: `Showing {n} of {total} rows and {m} of {total} columns.` The link appears when `url` is safe.

### `test`

`<p class="fw-embed__method">`, then a `<dl class="fw-embed__stats">` with the main results:

- the statistic, under its own name;
- `p-value`;
- with exactly one estimate and an interval, one entry named `{Estimate} (95% CI)` that reads `estimate (low to high)`;
- otherwise each estimate, then the interval as `95% CI` reading `low to high`.

Estimate names get an initial ASCII capital. A missing bound is `-∞` or `∞`.

A closed `<details class="fw-embed__details">` with the summary `Test details` follows when there is more to show. It holds:

- a `<dl>` with `Alternative` (Two-sided, One-sided (less), or One-sided (greater)), each parameter under its own name, and each null value as `Null value: {name}`;
- the test's tables, as data tables with model column labels;
- the metrics.

Numbers use result formatting.

### `image`

```html
<div class="fw-embed__media"><img class="fw-embed__img" src="{image.url}" alt="{alt}" loading="lazy" decoding="async" width="672" height="480"></div>
```

`alt` falls back to `title`, then the empty string. `width` and `height` appear only when both are positive integers. An unsafe `image.url` renders the document as `page`.

### `vega-lite`

```html
<div class="fw-embed__chart" data-fw-embed-chart>
<script type="application/json">{spec}</script>
<p class="fw-embed__fallback">{alt} <a href="{url}">View the chart</a></p>
</div>
```

The specification is written exactly as JavaScript's `JSON.stringify` writes it. That means shortest round-trip numbers in ECMAScript `Number::toString` form, integer-like keys first in ascending order, and only `"`, `\`, and control characters escaped. Then `<`, `>`, `&`, U+2028, and U+2029 become `<`, `>`, `&`, ` `, and ` `, so the text cannot close the `<script>` element. The JS bundle draws the chart with vega-embed and moves the fallback paragraph to a screen-reader-only class.

## Chart safety

Stored specifications render in the host site's origin. Chart renderers must keep vega from evaluating code or reaching the network:

- **No URLs.** A specification with a string `url` property anywhere (data, lookup data, Vega data sources) is not drawn. The fallback paragraph and its link stay.
- **Blocking loader.** vega receives a loader whose `load`, `sanitize`, `http`, and `file` reject every URL. Data loads fail, images get no source, and `href` encodings neither render as links nor navigate.
- **Expression interpreter.** vega parses expressions to an AST (`ast: true`) and evaluates them with vega's CSP-safe interpreter, so no expression becomes JavaScript through `new Function`. The pinned vega-embed 7.1.0 build includes vega-interpreter. vega-interpreter 2.x publishes no script build to pin separately. A site may pass its own interpreter with `configure({vegaInterpreter})`.
- **No embed options from the specification.** The renderer removes the top-level `usermeta` before drawing. vega-embed would otherwise merge `usermeta.embedOptions` over the renderer's options, including the loader.
- **No actions.** `actions: false` hides the export, source, and editor menu.
- **Text tooltips.** Tooltips are drawn with `textContent` in a `.fw-embed__tooltip` element. HTML in tooltip values is shown as text.
- Site options in `configure({vegaOptions})` may change the renderer, theme, or size. They cannot change the safety options above.

### `page`

```html
<div class="fw-embed__card">
<a class="fw-embed__card-title" href="{url}">{title}</a>
<p class="fw-embed__card-description">{description}</p>
<p class="fw-embed__author"><a href="{author.url}">{author.name}</a></p>
</div>
```

The title is a `<span>` when `url` is unsafe. It falls back to `Untitled`.

## Chart display

These rules apply to the JS bundle only. They change no server-rendered markup.

- **Theme.** The bundle decides between light and dark from the page. It checks, in order:
  - the nearest ancestor with `data-theme="dark"` or `data-theme="light"`, or the class `dark` or `light`;
  - the nearest ancestor with a background at least half opaque, which counts as dark when its relative luminance is below 0.2;
  - the computed `color-scheme`, when it names only `dark` or only `light`;
  - `prefers-color-scheme`.
- **Re-theming.** A `MutationObserver` on the `class`, `data-theme`, and `style` attributes of `<html>` and `<body>`, and a `prefers-color-scheme` listener, re-draw a chart once per animation frame when its theme, font, or colors changed.
- **Size.** A single Vega-Lite view whose top level has no `width` gets `width: "container"` and, unless it sets its own, `autosize: {"type": "fit", "contains": "padding"}`. Facet, repeat, and concat specifications, views with `row`, `column`, or `facet` encodings, and Vega specifications keep their own sizes. Height is unchanged. The chart fills the figure's width. A chart wider than the figure scrolls inside it.
- **Fonts and colors.** The bundle passes a vega config built from the figure's computed style:
  - `font` is the figure's `font-family`;
  - titles use the text color (`--fw-embed-fg`, else the inherited color);
  - axis and legend labels use `--fw-embed-muted`;
  - gridlines, domains, ticks, and the view outline use `--fw-embed-border`;
  - the background is transparent.

  The specification's own `config` and encodings take precedence over this config.

## Safe URLs

A URL is used in `href` or `src` only when it matches `^https?://` (any case) followed by one or more characters other than ASCII control characters, space, DEL, `"`, `'`, `<`, `>`, `\`, and the backtick. Other URLs are left out, along with the element or link that would carry them.

## Number formatting

All rounding works on the shortest round-trip decimal form of the number (JavaScript `toExponential()` digits). It rounds half away from zero. Rounding the binary value directly would make JavaScript and PHP disagree on ties such as 0.0625.

There are two contexts, which differ only in the number of places `p`:

- **Data formatting (`p` = 3):** values in `table` documents.
- **Result formatting (`p` = 2):** everything in `model` and `test` documents, including their tables and metrics.

| Rule | Applies to | Data (`p` = 3) | Result (`p` = 2) |
|---|---|---|---|
| Missing value | `null` | `—` | `—` |
| Booleans | `true`, `false` | `Yes`, `No` | `Yes`, `No` |
| Below the precision | nonzero numbers that round to zero at `p` decimals | `< 0.001`, `> -0.001` | `< 0.01`, `> -0.01` |
| Whole number | whole numbers outside decimal columns | `32`, `2026` | `32`, `2026` |
| `p` decimals | everything else | `0.036`, `-5.030` | `0.04`, `-5.03` |
| Grouping | integer parts of five or more digits get commas, at any size | `12,345.679`, `9999`, `1,000,000,000,000,000` | `12,345.68` |
| p-value | `p_value` columns and metrics (also `p.value`, `p-value`, `pvalue`, `Pr(>|t|)`, `Pr(>|z|)`, `Pr(>Chisq)`, `P>|t|`, `P>|z|`, any ASCII case) and test p-values, in both contexts | `< 0.001` below 0.001, else three decimals | the same |
| Percent | confidence levels, in both contexts | `x * 100` rounded to one decimal, with `.0` dropped: `95%`, `97.5%` | the same |

- Nothing uses scientific notation.
- Large numbers are written in full. Beyond 2^53 the digits are the shortest round-trip digits, padded with zeros: `123456789012345678` becomes `123,456,789,012,345,680`.
- "Below the precision" applies cell by cell, also inside intervals: `< 0.01 (< 0.01 to 0.13)`. A value that rounds up to `0.01` shows as `0.01`.
- Infinity and NaN show `—`.
- A negative number that rounds to zero loses its sign.
- The minus sign is ASCII `-`.
- Strings, dates, and large integers that Cloud sent as strings appear as sent.
- Non-finite results show `—`.
- `spec/format-cases.json` holds cases that both renderers must reproduce.

## Allowed hosts

A consumer fetches embed documents only from allowed hosts. An entry is a bare host name, such as `framework.pub`, which allows `https` on the default port, or an origin, such as `http://localhost:8000`, which allows exactly that scheme, host, and port. The default list is `["framework.pub"]`.

A URL matches when all of these hold:

- It is at most 2,048 characters of printable ASCII with no backslash.
- Its scheme is `http` or `https`.
- Its authority is a host name of letters, digits, hyphens, and dots, with an optional port. User info (`@`), percent-encoding, IPv6 brackets, and trailing dots fail.
- The host's last label is not numeric or hexadecimal, so IP literals in any form fail.
- Its scheme, lower-case host, and port (the default port removed) equal an allowed entry's.

Embed requests never send cookies and never follow redirects.

## Artifact URLs

On an allowed host, these paths are artifact URLs. Query strings and fragments are ignored.

| Path | Type |
|---|---|
| `/d/{key}` and `/d/{key}/versions/{n}` | `key` |
| `/@{handle}/{project}/{slug}` and `…/versions/{n}` | `path` |
| `/api/embed/v1/artifacts/{key}` and `…/versions/{n}` | `embed` |

`{key}` is 1 to 64 of `A-Z a-z 0-9 _ -`. Handles, projects, and slugs are 1 to 200 of `A-Z a-z 0-9 _ . -`, and never `.` or `..`. `{n}` is a positive integer of at most nine digits. A trailing slash is allowed on `key` and `path` URLs.

`embed` URLs are requested directly. Other artifact URLs are requested as `{origin}/api/embed/v1/resolve?url={origin}{path}`, with the inner URL percent-encoded.

## Theming

The stylesheet themes through `--fw-embed-font`, `--fw-embed-mono`, `--fw-embed-fg`, `--fw-embed-muted`, `--fw-embed-bg`, `--fw-embed-border`, and `--fw-embed-accent`. `--fw-embed-font` and `--fw-embed-fg` are unset by default, so the site's font and color apply. Muted text and borders mix the current text color with transparency, so they suit light and dark pages. The accent changes under `prefers-color-scheme: dark` and under a `data-theme="dark"` or `.dark` ancestor. A `data-theme="light"` or `.light` ancestor keeps the light accent. Figures sit on a white card in both themes.

Numbers use `--fw-embed-mono`: numeric table cells (`td.fw-embed__num`), numeric statistic values (`dd.fw-embed__num`), and the counts in notes (`span.fw-embed__num`), at 0.92em of the surrounding text with tabular figures. Labels, headers, and prose keep the host font. The default stack starts with JetBrains Mono. The stylesheet declares it with `@font-face`, weights 400 and 600, from `@fontsource/jetbrains-mono` 5.2.8 on jsDelivr, with `font-display: swap`. Its `unicode-range` is Basic Latin plus the no-break space, en and em dashes, and the minus sign. That covers the letters in interval cells ("to") and formulas, which share the mono stack. A site that sets `--fw-embed-mono` to another stack never downloads the font.

Type sizes are relative to the host text, so the host's scale applies. The figure is 0.875em of the surrounding text. Tables and statistics come to about 0.8125em of it, and captions, notes, the meta line, and the `details` summary to about 0.75em. Table rows use a lighter rule than the header. In print, closed `details` open through `::details-content` where supported, and through the JS bundle's `beforeprint` handler elsewhere.
