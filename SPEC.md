# framework.embed/v1 specification

This specification defines how external websites fetch and render published Framework artifacts. Framework Cloud serves embed documents, and the renderers in this repository convert those documents into HTML.

The sections "Embed document", "Renderer markup", and "Security rules for consumers" mirror `docs/plans/2026-11-16-artifact-embeds.md` in the Framework Cloud repository and should remain synchronized with it. Subsequent sections specify renderer behavior in full, tested against `spec/fixtures`.

## Embed API

A Framework Cloud server serves these public, cookie-free routes:

| Route | Response |
|---|---|
| `GET /api/embed/v1/resolve?url=<artifact URL>` | Embed document for a pasted artifact URL on that server |
| `GET /api/embed/v1/artifacts/{key}` | Embed document for the newest version |
| `GET /api/embed/v1/artifacts/{key}/versions/{n}` | Embed document for version `n` |
| `GET /api/embed/v1/artifacts/{key}/versions/{n}/image` | Figure file for image artifacts, which may redirect to a signed file URL |
| `GET /api/oembed?url=…&format=json` | oEmbed 1.0 `photo` response for images with known dimensions, or `link` for other artifacts |
| `GET /.well-known/framework.json` | Discovery document listing the routes above |

Private, password-protected, missing, and deleted artifacts return `404` with `Cache-Control: no-store`. Version URLs send `Cache-Control: public, max-age=3600`. Newest-version URLs send `Cache-Control: public, max-age=300`. Resolve and oEmbed responses send `max-age=3600` when the requested URL names a version and `max-age=300` otherwise.

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
  "alt": "Logistic regression of admitted with 4 terms and odds ratios.",
  "url": "https://framework.pub/d/aB3dE5fG7hJ9kL1m/versions/3",
  "embed_url": "https://framework.pub/api/embed/v1/artifacts/aB3dE5fG7hJ9kL1m/versions/3",
  "published_at": "2026-11-16T12:00:00Z",
  "author": {"name": "Ada Example", "url": "https://framework.pub/@ada"},
  "provider": {"name": "Framework", "url": "https://framework.pub"},
  "attribution": {"text": "Framework", "url": "https://framework.pub/d/aB3dE5fG7hJ9kL1m/versions/3"},
  "data": { "...": "the framework.artifact/v1 representation normalized by Framework Cloud" },
  "spec": null,
  "image": null
}
```

- `kind` is `model`, `table`, `test`, `image`, `vega-lite`, or `page`. Renderers treat unknown values as `page`.
- `data` is set for `model`, `table`, and `test` using the normalized representation from `ArtifactRepresentation::parse` (tables capped at 10, rows at 1,000, columns at 50, with `truncated`, `row_count`, `column_count`, and `precision_note`).
- `spec` is set for `vega-lite` and contains the Vega-Lite specification with inlined data.
- `image` contains `url`, `content_type`, `width`, and `height`. It is set for `image` and may be set for other kinds in future updates as a fallback. `width` and `height` may be `null`.
- `page` covers HTML documents, Quarto sites, PDFs, data files, and other outputs. Renderers display a link card with the title, description, author, and attribution link.
- `author`, `author.url`, and `description` may be `null`. New optional fields may appear within `v1`, and renderers ignore unknown fields.
- `alt` is a one-sentence text description for screen readers and image alt text.

## Renderer markup

The JavaScript and PHP renderers produce identical HTML for each embed document, verified against the shared fixtures in `spec/fixtures`. All artifact text is escaped before insertion. Every CSS class begins with `fw-embed`.

```html
<figure class="fw-embed fw-embed--model" data-fw-embed-kind="model" data-fw-embed-key="…" data-fw-embed-version="3">
  <div class="fw-embed__body">…kind-specific content…</div>
  <figcaption class="fw-embed__caption">
    <span class="fw-embed__title">Admission model</span>
    <a class="fw-embed__credit" href="…attribution.url…" target="_blank" rel="noopener">Framework</a>
  </figcaption>
</figure>
```

- `model`: one coefficient table per coefficient-like table (estimate, exponentiated ratio when `model.exponentiate` is set, standard error, test statistic, p-value, and confidence interval), followed by fit statistics and additional tables in a `Model details` disclosure.
- `table`: a static `<table>` with the first 25 rows and a note stating the row count with a link to the full artifact.
- `test`: method, test statistic, p-value, estimates, and confidence interval, followed by parameters and additional details in a `Test details` disclosure.
- `image`: `<img src alt loading="lazy">`.
- `vega-lite`: `<div class="fw-embed__chart" data-fw-embed-chart>` containing the specification in `<script type="application/json">` and a fallback link to the artifact. The JavaScript bundle renders the chart with `vega-embed`. Without JavaScript, the fallback link remains visible.
- `page`: a link card.

The stylesheet exposes custom properties (`--fw-embed-font`, `--fw-embed-mono`, `--fw-embed-fg`, `--fw-embed-muted`, `--fw-embed-bg`, `--fw-embed-border`, and `--fw-embed-accent`). It follows `prefers-color-scheme` and any `data-theme="dark"` or `.dark` ancestor.

## Security rules for consumers

- Fetch embed documents only from trusted hosts: framework.pub by default, plus servers configured by an administrator. Validate the host of every pasted URL before sending a request.
- Escape all artifact text and render SVG files only inside `<img>` elements.
- Cache live embed documents no longer than their `Cache-Control` header allows, and drop cached copies when the server responds with `404` or `410`.
- Load `vega-embed` from self-hosted files or a pinned CDN version.

## Consumer tiers

Consumers handle embed documents in one of two tiers.

### Stored

A consumer may save an embed document alongside its own content and render from that saved copy indefinitely. CMS blocks, static site builds, and Academic.page pages use this tier. Stored copies refresh only when the author requests an update.

- Renderers continue supporting all valid `framework.embed/v1` documents. New fields within `v1` are optional, so older stored documents remain valid.
- `<framework-artifact>` renders a stored document from a direct child `<script type="application/json">` without making a network request. Its `src` attribute is used only when the inline document is absent or is not `framework.embed/v1`.

```html
<framework-artifact src="https://framework.pub/d/aB3dE5fG7hJ9kL1m/versions/3">
  <script type="application/json">{"schema":"framework.embed/v1", …}</script>
</framework-artifact>
```

Write the inline `<script type="application/json">` payload using the same character escaping as chart specifications (see `vega-lite` below).

### Live

A consumer may fetch and cache the embed document dynamically.

- Cache the document no longer than its `Cache-Control` header allows.
- Never block page rendering on embed requests. `<framework-artifact>` keeps its child elements visible until the document loads, and server-side consumers render a cached copy or a plain link while refreshing in the background.
- Drop the cached copy when the server responds with `404` or `410`.

### Trimmed tables

Framework Cloud trims each table in an embed document to its first 25 rows. `row_count` preserves the full row count, and the rendered note reads `Showing 25 of {row_count} rows.` Tables with fewer rows than `row_count` render all supplied rows. Decimal column detection inspects only the first 25 rows, so trimmed and untrimmed documents render identically.

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

A model shows each coefficient table once, in full. Everything else goes in a closed `<details class="fw-embed__details">` whose `<summary>` reads `Model details`. Readers can open it without JavaScript.

Inside `fw-embed__body`, in order:

1. `<p class="fw-embed__meta">`: the model's common name and its response, joined by ` · `. For example, `Logistic regression · Response: am`. "Model names" below has the mapping.
2. `<p class="fw-embed__formula"><code>`: `formula`, when present. Without a formula, a `command` metric with a string value takes this slot. That is how Stata models show the command that fit them, such as `regress price mpg weight i.foreign`. A string `command` metric never appears in the statistics, even when a formula is present.
3. Each coefficient table (`fw-embed__table--coefficients`).
4. The `Model details` disclosure, holding any remaining content:
   - every other table (random effects, smooth terms, ANOVA, and others) rendered as a data table with model column labels, up to 25 rows.
   - `<dl class="fw-embed__stats">` containing the model metrics.

   When no additional tables or metrics remain, the disclosure is omitted.

#### Model names

The name comes from `model.type`, `model.family`, and `model.link`, compared in ASCII lower case. The family and link appear only when the name does not imply them.

| Type | Family and link | Name |
|---|---|---|
| `linear` | any | Ordinary least squares regression |
| `glm` | gaussian, identity or no link | Ordinary least squares regression |
| `glm` | binomial, logit or no link | Logistic regression |
| `glm` | binomial, probit | Probit regression |
| `glm` | binomial, cloglog | Complementary log-log regression |
| `glm` | binomial, other link | Binomial regression ({link} link) |
| `glm` | poisson | Poisson regression, with ({link} link) unless the link is log or absent |
| `glm` | negative binomial | Negative binomial regression, with ({link} link) unless the link is log or absent |
| `glm` | gamma | Gamma regression ({link} link), or Gamma regression without a link |
| `glm` | quasi{rest} | Quasi-{rest} regression (Quasi-Poisson for quasipoisson), or Quasi-likelihood regression for `quasi` |
| `mixed` | gaussian or no family | Linear mixed model, with ({link} link) unless the link is identity or absent |
| `mixed` | binomial, logit or no link; probit; other | Logistic mixed model; Probit mixed model; Binomial mixed model ({link} link) |
| `mixed` | poisson; negative binomial; gamma | Poisson mixed model; Negative binomial mixed model; Gamma mixed model, with the link as for `glm` |
| `survival` | `exponentiate` is `Hazard ratio` | Cox proportional hazards model |
| `survival` | otherwise | Parametric survival model |
| `ordinal` | logit or no link; probit; other | Ordinal logistic regression; Ordinal probit regression; Ordinal regression ({link} link) |
| `multinomial` | any | Multinomial logistic regression |
| `additive` | gaussian or no family | Generalized additive model |
| `additive` | other family | Generalized additive model ({family} family, {link} link) |
| `fixed_effects` | poisson; binomial with logit or no link; other | Fixed-effects Poisson regression; Fixed-effects logistic regression; Fixed-effects regression |
| `quantile` | any | Quantile regression |
| `linear_ml` | any | Linear model |
| `tree_ensemble` | any | Tree ensemble model |

Wherever a link name appears, `sqrt` reads `square-root` and `cloglog` reads `complementary log-log`, so `ordinal` with `cloglog` is Ordinal regression (complementary log-log link).

Any other combination falls back to the type label, then `{family} family, {link} link` as sent. In this fallback the families `gaussian`, `tweedie`, and `poisson` read Gaussian, Tweedie, and Poisson. The first ASCII letter of the meta line is capitalized, so an unknown type `spline` with a log link reads `Spline · log link`. Type labels: `linear` Linear model, `glm` Generalized linear model, `mixed` Mixed model, `survival` Survival model, `ordinal` Ordinal model, `multinomial` Multinomial model, `additive` Additive model, `fixed_effects` Fixed-effects model, `quantile` Quantile regression, `linear_ml` Linear model, `tree_ensemble` Tree ensemble model. Other types appear as sent, and `other` is left out. `spec/model-names.json` holds cases both renderers must reproduce.

A table is a coefficient table when its `role` is `coefficients` (or it is the first table and has no role) and it has `term` and `estimate` columns.

A model with no coefficient table but with an `importance` table shows that table's first 10 rows in place of item 3. When the document has more rows, the disclosure continues the same table with rows 11 to 25 and the note `Showing rows 11 to 25 of {row_count}.`, linked to the artifact when rows remain. The visible part's note reads `Showing 10 of {row_count} rows. The rest are in Model details.` Both parts judge decimal columns on the first 25 rows, so they format alike.

A model with no coefficient or importance table shows its tables and metrics without a disclosure.

A coefficient table has these columns, in order. Columns whose data is absent are left out.

- Term, a row header (`<th scope="row">`).
- Any columns outside the standard set (`term`, `estimate`, `std_error`, `statistic`, `p_value`, `conf_low`, `conf_high`, `df`).
- Estimate, on the model's scale.
- When `model.exponentiate` is set, the exponentiated estimate with its exponentiated interval. The header is the label with the interval, such as `Odds ratio (95% CI)`, with the interval part in `<span class="fw-embed__ci">` as in the cells. The cell reads `exp(estimate) (exp(low) to exp(high))`, such as `1.04 (1.00 to 1.07)`. The interval part sits in `<span class="fw-embed__ci">`, which the stylesheet places on a second line: `<td class="fw-embed__num">1.04 <span class="fw-embed__ci">(1.00 to 1.07)</span></td>`. Without interval columns, the header is the label alone and the cell is `exp(estimate)`. Intercept rows show `—`. A term is an intercept when, in ASCII lower case, it is `(intercept)`, `intercept`, `const`, or `_cons`.
- Std. error.
- Statistic.
- p-value.
- The interval on the model's scale, as `low to high`.

The interval header takes its level from the `confidence_level` metric. Without that metric, the header is `CI`. Estimates, ratios, standard errors, statistics, and bounds use result formatting with forced decimals. The ratio cell for the glm fixture's `wt` reads `< 0.01 (< 0.01 to 0.13)`. On narrow screens the table scrolls inside its figure.

`exp` is fdlibm's `exp`, computed with IEEE basic operations only, so the JS and PHP renderers agree bit for bit. Platform `exp` functions can differ in the last place.

Metric labels: `nobs` Observations, `df_resid` Residual df, `df_model` Model df, `aic` AIC, `bic` BIC, `rsquared` and `r.squared` R², `rsquared_adj` and `adj.r.squared` Adjusted R², `pseudo_rsquared` Pseudo R², `sigma` Residual SD, `llf` and `log_likelihood` Log likelihood, `deviance` Deviance, `null.deviance` Null deviance, `dispersion` Dispersion, `converged` Converged, `reml` REML, `confidence_level` Confidence level, `interval_method` Interval method, `vcov_type` Standard errors, `n_estimators` Trees, `max_depth` Maximum depth, `learning_rate` Learning rate, `n_features` Features, `importance_type` Importance type, `objective` Objective, `n_events` Events, `concordance` Concordance, `theta` Theta, `command` Command. `n_groups.<factor>` becomes `Groups: <factor>`. Other names replace `_` and `.` with spaces and capitalize an initial ASCII letter.

Metric values: `confidence_level` as a percent, `interval_method` `wald-t` as Wald t, `wald-normal` as Wald normal, `profile` as Profile likelihood, and `bootstrap` as Bootstrap. p-value names use p-value formatting. Other numbers use result formatting.

Statistic pairs render in two forms:

- Numeric values render as `<div><dt>…</dt><dd class="fw-embed__num">…</dd></div>` for metric numbers, percentages, and every test value except `Alternative`.
- Text values render as `<div class="fw-embed__stat--text">` for entries such as Standard errors and Interval method, using the host font. When a value exceeds 24 Unicode code points, the `<div>` adds `fw-embed__stat--wide` and spans the full grid row with the label above the value.

Labels wrap only between words.

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
<p class="fw-embed__note">Showing <span class="fw-embed__num">25</span> of <span class="fw-embed__num">60</span> rows. <a href="{url}">View full table</a></p>
<p class="fw-embed__note">{precision_note}</p>
```

- The first 25 rows are shown.
- Number columns get `class="fw-embed__num"` on their header and cells.
- A number column is decimal when any shown value in it is not a whole number. Every value in a decimal column gets the full number of decimals: three in `table` documents (data formatting), two in model and test tables (result formatting).
- Model and test tables label known columns: `term` Term, `estimate` Estimate, `std_error` Std. error, `statistic` Statistic, `p_value` p-value, `conf_low` CI lower, `conf_high` CI upper, `group` Group, `variance` Variance, `std_dev` Std. dev., `correlation` Correlation, `feature` Feature, `importance` Importance, `edf` EDF, `ref_df` Ref. df. A column `label` wins over these.
- The note appears when `row_count` exceeds the rows shown or `column_count` exceeds the columns sent: `Showing {n} of {total} rows and {m} of {total} columns.` The link appears when `url` is safe.

### `test`

`<p class="fw-embed__method">`, followed by `<dl class="fw-embed__stats">` with the primary results:

- Test statistic under its own name.
- `p-value`.
- When there is one estimate and a confidence interval, a single `{Estimate} (95% CI)` entry reading `estimate (low to high)`.
- Otherwise, each estimate followed by the confidence interval entry (`95% CI`) reading `low to high`.

Estimate names receive an initial ASCII capital. Missing bounds render as `-∞` or `∞`.

A closed `<details class="fw-embed__details">` with `<summary>Test details</summary>` follows when additional fields exist:

- A `<dl>` containing `Alternative` (`Two-sided`, `One-sided (less)`, or `One-sided (greater)`), each parameter under its own name, and each null value labeled `Null value: {name}`.
- Any test tables rendered as data tables with model column labels.
- Any metrics.

Numbers use result formatting.

### `image`

```html
<div class="fw-embed__media"><img class="fw-embed__img fw-embed__img--vector" src="{image.url}" alt="{alt}" loading="lazy" decoding="async" width="672" height="480" style="max-width: min(100%, calc(80vh * 672 / 480))"></div>
```

`alt` falls back to `title`, then the empty string. `width` and `height` appear only when both are positive integers. An unsafe `image.url` renders the document as `page`.

A figure whose `image.content_type` starts with `image/svg+xml` (ASCII case-insensitive) is a vector figure and gets `fw-embed__img--vector`. Vector figures fill the figure's width. When `width` and `height` are known, the `style` attribute caps the width at `80vh` times the aspect ratio, so a tall figure narrows instead of being letterboxed. Other figures are raster figures. They scale down to fit the figure and never grow past their natural width. Every figure's height stops at `80vh`, keeping its proportions. The media panel is white in both themes, with a thin `0.25em` band around the figure.

### `vega-lite`

```html
<div class="fw-embed__chart" data-fw-embed-chart>
<script type="application/json">{spec}</script>
<p class="fw-embed__fallback">{alt} <a href="{url}">View chart</a></p>
</div>
```

The specification is written exactly as JavaScript's `JSON.stringify` writes it. That means shortest round-trip numbers in ECMAScript `Number::toString` form, integer-like keys first in ascending order, and only `"`, `\`, and control characters escaped. Then `<`, `>`, `&`, U+2028, and U+2029 become `<`, `>`, `&`, ` `, and ` `, so the text cannot close the `<script>` element. The JS bundle draws the chart with vega-embed and moves the fallback paragraph to a screen-reader-only class.

## Chart safety

Stored specifications render in the host site's origin. Chart renderers must prevent Vega from evaluating arbitrary JavaScript or making network requests:

- **No URLs.** Any specification containing a string `url` property (in data, lookup transforms, or Vega data sources) is not rendered, leaving the fallback paragraph and link visible.
- **Blocking loader.** Vega receives a loader whose `load`, `sanitize`, `http`, and `file` methods reject every URL. External data loads fail, image marks receive no source, and `href` encodings neither render as links nor navigate.
- **Expression interpreter.** Vega parses expressions to an AST (`ast: true`) and evaluates them with its CSP-safe interpreter rather than `new Function`. The pinned `vega-embed` 7.1.0 build includes `vega-interpreter`. Sites may supply a custom interpreter with `configure({vegaInterpreter})`.
- **No embed options from the specification.** The renderer strips top-level `usermeta` before rendering so `usermeta.embedOptions` cannot override safety options or the loader.
- **No actions.** `actions: false` hides the export, source, and editor menu.
- **Text tooltips.** Tooltips render via `textContent` in `.fw-embed__tooltip`, showing HTML in tooltip values as plain text.
- **Configuration precedence.** `configure({vegaOptions})` may customize the renderer, theme, or size, while the safety options above always take precedence.

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

These rules apply to the JavaScript bundle and do not alter server-rendered markup.

- **Theme.** The bundle selects light or dark mode by checking, in order:
  - the nearest ancestor with `data-theme="dark"`, `data-theme="light"`, `.dark`, or `.light`.
  - the nearest ancestor with a background at least 50% opaque, treated as dark when its relative luminance is below `0.2`.
  - the computed `color-scheme` when it specifies solely `dark` or `light`.
  - `prefers-color-scheme`.
- **Retheming.** A `MutationObserver` on the `class`, `data-theme`, and `style` attributes of `<html>` and `<body>`, paired with a `prefers-color-scheme` listener, redraws charts once per animation frame when theme, font, or colors change.
- **Size.** A single Vega-Lite view without a top-level `width` receives `width: "container"` and, unless specified, `autosize: {"type": "fit", "contains": "padding"}`. Facet, repeat, and concat specifications, views with `row`, `column`, or `facet` encodings, and Vega specifications keep their declared dimensions. Height is unchanged. Charts wider than the figure scroll horizontally inside it.
- **Fonts and colors.** The bundle passes a Vega configuration derived from the figure's computed style:
  - `font` uses the figure's `font-family`.
  - Titles use `--fw-embed-fg`, falling back to the inherited text color.
  - Axis and legend labels use `--fw-embed-muted`.
  - Gridlines, domains, ticks, and the view outline use `--fw-embed-border`.
  - The chart background is transparent.

  The specification's own `config` and encodings take precedence over these defaults.

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

- Values never use scientific notation.
- Large numbers are written in full. Beyond 2^53, digits come from the shortest round-trip form padded with zeros, so `123456789012345678` formats as `123,456,789,012,345,680`.
- Precision thresholds apply cell by cell, including interval bounds: `< 0.01 (< 0.01 to 0.13)`. A value that rounds to `0.01` displays as `0.01`.
- `Infinity`, `-Infinity`, `NaN`, and non-finite results display as `—`.
- A negative number that rounds to zero omits its minus sign.
- The minus sign is ASCII `-`.
- Strings, dates, and large integers sent as strings by Framework Cloud appear unchanged.
- `spec/format-cases.json` defines test cases that both renderers must reproduce.

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

The stylesheet themes through `--fw-embed-font`, `--fw-embed-mono`, `--fw-embed-fg`, `--fw-embed-muted`, `--fw-embed-bg`, `--fw-embed-border`, and `--fw-embed-accent`. `--fw-embed-font` and `--fw-embed-fg` are unset by default, so the site's font and color apply. Muted text and borders mix the current text color with transparency, so they suit light and dark pages. The accent changes under `prefers-color-scheme: dark` and under a `data-theme="dark"` or `.dark` ancestor. A `data-theme="light"` or `.light` ancestor keeps the light accent. Figures sit on a white panel in both themes.

Numbers use `--fw-embed-mono`: numeric table cells (`td.fw-embed__num`), numeric statistic values (`dd.fw-embed__num`), and the counts in notes (`span.fw-embed__num`), at 0.92em of the surrounding text with tabular figures. Labels, headers, and prose keep the host font. The default stack starts with JetBrains Mono. The stylesheet declares it with `@font-face`, weights 400 and 600, from `@fontsource/jetbrains-mono` 5.2.8 on jsDelivr, with `font-display: swap`. Its `unicode-range` is Basic Latin plus the no-break space, en and em dashes, and the minus sign. That covers the letters in interval cells ("to") and formulas, which share the mono stack. A site that sets `--fw-embed-mono` to another stack never downloads the font.

Type sizes are relative to the host text, so the host's scale applies. The figure is 0.875em of the surrounding text. Tables and statistics come to about 0.8125em of it, and captions, notes, the meta line, and the `details` summary to about 0.75em. Table rows use a lighter rule than the header. In print, closed `details` open through `::details-content` where supported, and through the JS bundle's `beforeprint` handler elsewhere.
