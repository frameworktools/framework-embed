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

For a code snippet, `?output=none` on a pasted URL or an artifact route sets `data.display.output` to `false`, so the output starts hidden. The document's `url` and `embed_url` keep the option. Other kinds ignore it.

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

- `kind` is `model`, `table`, `test`, `code_snippet`, `dag`, `tree`, `image`, `vega-lite`, or `page`. Renderers treat unknown values as `page`.
- `data` is set for `code_snippet` with the normalized snippet (see `code_snippet` below), for `dag` with the laid-out graph (see `dag` below), for `tree` with the sorted folder tree (see `tree` below), and for `model`, `table`, and `test` using the normalized representation from `ArtifactRepresentation::parse` (tables capped at 10, rows at 1,000, columns at 50, with `truncated`, `row_count`, `column_count`, and `precision_note`).
- `spec` is set for `vega-lite` and contains the Vega-Lite specification with inlined data.
- `image` contains `url`, `content_type`, `width`, and `height`. It is set for `image`. It is optional for `vega-lite`, where it is a static rendering of the same chart, such as the SVG a ggplot was drawn to, shown wherever the chart cannot draw (see `vega-lite` below). It may be set for other kinds in future updates as a fallback. `width` and `height` may be `null`.
- `page` covers HTML documents, Quarto sites, PDFs, data files, and other outputs. Renderers display a link card with the title, description, author, and attribution link.
- `author`, `author.url`, `title`, and `description` may be `null`. The artifact's owner sets what embeds show on Framework Cloud, and Cloud leaves out what they turn off (see "Display settings"). New optional fields may appear within `v1`, and renderers ignore unknown fields.
- `alt` is a one-sentence text description for screen readers and image alt text.

## Model variables

A `model` representation may carry two optional fields that describe the data behind it. Packages fill them from the data the model was fit on (the model frame), never from rows outside it. Both may be absent, and renderers show the model without them.

```json
"variables": [
  {"name": "body_mass_g", "role": "response", "type": "number", "label": "Body mass (g)",
   "summary": {"count": 342, "missing": 0, "mean": 4201.75, "sd": 801.95, "min": 2700, "max": 6300}},
  {"name": "flipper_length_mm", "role": "predictor", "type": "number",
   "summary": {"count": 342, "missing": 0, "mean": 200.92, "sd": 14.06, "min": 172, "max": 231}},
  {"name": "species", "role": "predictor", "type": "categorical",
   "levels": ["Adelie", "Chinstrap", "Gentoo"], "reference": "Adelie",
   "summary": {"count": 342, "missing": 0, "distinct": 3,
               "top": [{"value": "Adelie", "count": 151}, {"value": "Gentoo", "count": 123}, {"value": "Chinstrap", "count": 68}]}}
],
"terms": [
  {"term": "flipper_length_mm", "parts": [{"variable": "flipper_length_mm"}]},
  {"term": "speciesChinstrap", "parts": [{"variable": "species", "level": "Chinstrap"}]},
  {"term": "flipper_length_mm:speciesGentoo", "parts": [{"variable": "flipper_length_mm"}, {"variable": "species", "level": "Gentoo"}]}
]
```

`variables` lists each variable once, at most 100:

- `name`: the variable's name in the data, before any transformation (`bill_depth_mm` for a `log(bill_depth_mm)` term).
- `role`: `response`, `predictor`, `group` (a random-effects grouping factor), `weight`, or `offset`. Optional.
- `type`: `number`, `categorical` (a factor, category, or labeled integer), `boolean`, `date`, `datetime`, or `string`.
- `label`: the variable's label, when the data carries one: R's `label` attribute, a pandas `attrs` entry, or Stata's variable label. Optional.
- `levels`: a categorical variable's levels in model order, at most 50. For a Stata value-labeled variable, the value labels. Optional.
- `reference`: the level the model's coefficients compare against, when it has one. Optional.
- `summary`: the same object as a table column's `summary` (`count`, `missing`, `distinct`, `mean`, `sd`, `min`, `q1`, `median`, `q3`, `max`, `top`), computed over the rows the model used. `count` is those rows, so `missing` is usually 0. No `histogram`. Optional.

`terms` maps coefficient table terms to variables, at most 500. Each entry's `term` matches a `term` cell exactly. `parts` names one variable per factor of the term, in order, with `level` for a categorical variable's level. The intercept and terms a package can't map are left out. A term from a transformation, such as `log(x)` or `poly(x, 2)1`, maps to the variable it transforms.

Framework Cloud keeps both fields when they fit this contract and drops entries that don't, as it does for column summaries.

## Bayesian models

A `model` representation fit by posterior sampling sets `model.inference` to `"bayesian"`. Everything else in the representation keeps its meaning, with these differences. Representations without `inference` are frequentist.

Coefficient tables (`role: "coefficients"`, and `random_effects` for group-level terms) use these columns, summarized over the posterior draws:

| Column | Contents |
|---|---|
| `term` | The parameter, named as the package prints it (`speciesChinstrap`, `sd(Intercept)` with `group` set for group-level terms). |
| `estimate` | Posterior mean. |
| `std_error` | Posterior standard deviation. |
| `conf_low`, `conf_high` | Equal-tailed credible interval, at the level in the `confidence_level` metric (0.95 unless the user asked otherwise). |
| `rhat` | R-hat (rank-normalized split R-hat where the package computes it). |
| `ess_bulk`, `ess_tail` | Bulk and tail effective sample sizes. |
| `ratio_estimate`, `ratio_std_error`, `ratio_conf_low`, `ratio_conf_high` | Only when `model.exponentiate` is set: the same summaries of the exponentiated draws. Never the exponent of the summaries. |

No `statistic` or `p_value`. `interval_method` names the interval, such as `Equal-tailed`; an HDI is allowed and named `HDI`.

Metrics a Bayesian model may carry, beside `nobs`, `confidence_level`, and `interval_method`: `chains`, `draws` (post-warmup draws, all chains), `warmup` (per chain), `sampler` (such as `NUTS (Stan)`), `divergent_transitions`, `max_treedepth_hits`, `bayes_r2`, `elpd_loo`, `elpd_loo_se`, `looic`, `p_loo`, `waic`, and from Stata `rhat_max`, `acceptance_rate`, `efficiency_min`, `efficiency_avg`, `efficiency_max`, `dic`, and `log_marginal_likelihood`.

A `priors` table (`role: "priors"`) lists the priors: `parameter` (the class, coefficient, and group, as the package names them), `prior` (the distribution as text, such as `student_t(3, 0, 2.5)`), and `source` (`default` or `user`) when known.

Renderers:

- Name the model with `Bayesian ` before the usual name: `Bayesian logistic regression · Outcome: am`.
- Label the coefficient columns Posterior mean, Posterior SD, and `{level} interval` (such as `95% interval`), and show R-hat (two decimals) and Bulk ESS and Tail ESS (whole numbers) after the interval. On the ratio scale, the estimate column takes the ratio's label and the four `ratio_` columns supply the values; without them, the table shows only the model scale.
- Flag an R-hat above 1.01 and any divergent transitions in a note under the coefficients: `R-hat above 1.01 for {n} parameters: the chains may not have converged.` and `{n} divergent transitions: the posterior may be biased.`
- Put the priors table in Model details with the other extra tables, titled Priors.

## Display settings

The artifact's owner decides on Framework Cloud what its embeds show, and the packages can set the same settings when they publish. Cloud leaves out what is turned off: a hidden title, description, or author is `null`; a hidden DAG analysis, DAGitty code, or source code drops `data.analysis`, `data.dagitty`, or `data.code`; hidden variable tips drop `data.variables` and `data.terms`; hidden column summaries drop each column's `summary`; hidden snippet links leave `data.display.links` empty; hidden tree notes drop each node's `note`, and hidden ignored paths drop ignored stubs and groups (private stubs stay); and a chart shown without interaction arrives as `image`. Renderers need nothing for these.

Two settings are flags, since what they hide also feeds what stays:

- `data.display.details: false` on a `model` or `test`: no `Model details` or `Test details` disclosure. An importance table shows its first 10 rows, with the note a plain data table gets, and no continuation.
- `data.display.legend: false` on a `dag`: no legend.
- `data.display.full: true` on a `tree`: the whole tree shows, never clipped. Framework Cloud sets it from the **Whole tree** display setting, off by default.
- `data.display.notes: false` on a `tree`: no notes. `data.display.ignored: false`: no ignored groups, no ignored folders unless they are private, and private folders without their rule. Cloud strips these itself; the flags guard a document that still carries them.
- `display.credit: false`, at the top level of any document: no link back (`fw-embed__credit`). When the title is hidden too, or the document is a `page`, the caption is left out.

Older renderers ignore the flags and show the sections.

## Renderer markup

The JavaScript and PHP renderers produce identical HTML for each embed document, verified against the shared fixtures in `spec/fixtures`. All artifact text is escaped before insertion. Every CSS class begins with `fw-embed`.

```html
<figure class="fw-embed fw-embed--model" data-fw-embed-kind="model" data-fw-embed-key="…" data-fw-embed-version="3">
  <figcaption class="fw-embed__caption">
    <span class="fw-embed__title">Admission model</span>
    <a class="fw-embed__credit" href="…attribution.url…" target="_blank" rel="noopener">Framework</a>
  </figcaption>
  <div class="fw-embed__body">…kind-specific content…</div>
  <div class="fw-embed__about">
    <span class="fw-embed__description">Admissions by GRE score and rank.</span>
  </div>
</figure>
```

- `model`: one coefficient table per coefficient-like table (estimate, standard error, test statistic, p-value, and confidence interval), followed by fit statistics and additional tables in a `Model details` disclosure. When `model.exponentiate` is set, a switch chooses between the ratio scale and the model scale (see "Scale switch").
- `table`: a static `<table>` with the first 25 rows and a note stating the row count with a link to the full artifact.
- `test`: method, test statistic, p-value, estimates, and confidence interval, followed by parameters and additional details in a `Test details` disclosure.
- `code_snippet`: a bordered panel with a header, the code blocks, and the output blocks between them, then links to the notebook and model.
- `dag`: an inline SVG of the graph and a legend of its roles, then disclosures for its description, analysis, DAGitty specification, and code.
- `tree`: a monospace folder tree with box-drawing guides, folders as nested disclosures, and notes in a column.
- `image`: `<img src alt loading="lazy">`.
- `vega-lite`: `<div class="fw-embed__chart" data-fw-embed-chart>` containing the specification in `<script type="application/json">` and a fallback: the chart's static image when the document has one, otherwise its alt text and a link to the artifact. The JavaScript bundle renders the chart with `vega-embed`, which replaces the fallback. Without JavaScript, the fallback remains visible.
- `page`: a link card.

The stylesheet exposes custom properties, listed under "Theming". It follows `prefers-color-scheme` and any `data-theme="dark"` or `.dark` ancestor.

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

`<figcaption class="fw-embed__caption">`, when it holds anything, comes first in the figure, before `fw-embed__body`, except for `page`, where it follows the body. It holds, in order:

- `fw-embed__title`: `title`, when present. Left out for `page`.
- `fw-embed__credit`, unless `display.credit` is `false`: `attribution.text` (default `Framework`), linked to `attribution.url`, or to `url` when that is missing or unsafe, with `target="_blank" rel="noopener"`. A `<span>` when neither URL is safe.

After `fw-embed__body`, a `<div class="fw-embed__about">` holds, in order, when either is present (never for `page`):

- `fw-embed__description`: `description`.
- `fw-embed__author`: `author.name`, linked to `author.url` when that URL is safe.

### `model`

A model shows each coefficient table once, in full. Everything else goes in a closed `<details class="fw-embed__details">` whose `<summary>` reads `Model details`. Readers can open it without JavaScript.

Inside `fw-embed__body`, in order:

1. Each coefficient table (`fw-embed__table--coefficients`), and any random effects table.
2. The `Model details` disclosure, holding, in order:
   - `<p class="fw-embed__meta">`: the model's common name and its outcome (`model.response`), joined by ` · `. For example, `Logistic regression · Outcome: am`. The text is in a `<span>`, and the outcome follows it in `<code class="fw-embed__var">`. "Model names" below has the mapping.
   - `<p class="fw-embed__formula"><code>`: `formula`, when present. Without a formula, a `command` metric with a string value takes this slot. That is how Stata models show the command that fit them, such as `regress price mpg weight i.foreign`. A string `command` metric never appears in the statistics, even when a formula is present.
   - every other table (smooth terms, ANOVA, and others) rendered as a data table with model column labels, up to 25 rows.
   - `<dl class="fw-embed__stats">` containing the model metrics.

   When none of these are present, the disclosure is omitted. A model with nothing to show outside the disclosure shows its contents directly.

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
- Estimate.
- Std. error.
- Statistic.
- p-value.
- The interval, as `low to high`.

The interval header takes its level from the `confidence_level` metric, such as `95% CI`. Without that metric, the header is `CI`. Estimates, standard errors, statistics, and bounds use result formatting with forced decimals. On narrow screens the table scrolls inside its figure.

#### Scale switch

A frequentist model with `model.exponentiate` set renders each coefficient table twice, once on the ratio scale and once on the model scale, with a switch between them. A Bayesian model does the same only when its table has `ratio_estimate`.

```html
<form class="fw-embed__scales">
<div class="fw-embed__switch" role="radiogroup" aria-label="Scale">
<label><input type="radio" name="fw-embed-scale" value="ratio" checked=""> Odds ratio</label>
<label><input type="radio" name="fw-embed-scale" value="model"> Log odds</label>
</div>
<div class="fw-embed__scale fw-embed__scale--ratio">…coefficient table…</div>
<div class="fw-embed__scale fw-embed__scale--model">…coefficient table…</div>
</form>
```

- The first label is `model.exponentiate`. The second is `Log odds` for `Odds ratio`, `Log hazard` for `Hazard ratio`, `Log rate` for `Rate ratio` and `Incidence rate ratio`, `Log time` for `Time ratio`, and `Coefficient` otherwise.
- The ratio table's estimate header is the first label. For a frequentist model, its estimate and interval bounds are `exp` of the model-scale values, and its standard error is the delta-method `exp(estimate) * std_error`. Statistic and p-value are unchanged. Intercept rows are exponentiated like any other row. A Bayesian model's ratio table reads the `ratio_` columns instead (see "Bayesian models").
- The model table's estimate header is the second label, and its values come from the document unchanged.
- The stylesheet shows the ratio table and hides the model table until the `model` input is checked, using `:has()`. No JavaScript is involved.
- Each embed's radio inputs sit in their own `<form>`, so embeds on one page switch independently even though the inputs share a `name`. A host that removes the inputs loses the switch, and the stylesheet hides the empty switch and shows only the ratio table.

`exp` is fdlibm's `exp`, computed with IEEE basic operations only, so the JS and PHP renderers agree bit for bit. Platform `exp` functions can differ in the last place.

#### Metrics

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

### Variable tips

A coefficient table's term cell gets a tip when `terms` maps it and every variable it names is in `variables`. A data table's column header (in `table` documents) gets one when the column has a `label` or a `summary` that yields a sentence. Everything else stays plain text.

```html
<th scope="row"><button type="button" class="fw-embed__term" popovertarget="fw-embed-tip-{key}-{version}-{n}">speciesChinstrap</button><span class="fw-embed__tip" id="fw-embed-tip-{key}-{version}-{n}" popover=""><span class="fw-embed__tip-block"><span class="fw-embed__tip-name">species = Chinstrap</span><span class="fw-embed__tip-label">Penguin species</span><span class="fw-embed__tip-detail">Compared with Adelie. 68 of 342 rows.</span></span></span></th>
```

- Ids count from 1 in document order within one embed. `{key}-{version}-` is left out when `key` has characters other than ASCII letters, digits, `_`, and `-`; `version` is `0` when it isn't an integer. The JS bundle renumbers repeats when one artifact is embedded twice on a page.
- One `fw-embed__tip-block` per part of the term. The name is the variable, with ` = {level}` for a level. The label line appears when the variable has a `label` other than its name. A column's tip names the column, with its `label` as the label line when the header shows the label.
- The detail line joins these sentences with spaces, leaving out any whose numbers are missing:
  - A level: `Compared with {reference}.` when the reference differs from the level, then `{count} of {n} rows.` from the `top` entry whose value is the level: a string as is, an integer in digits, or a boolean as `true` or `false` in any ASCII case.
  - A number: `Mean {mean}, SD {sd}, range {min} to {max}.`, each part present only when its value is, the first capitalized. Values use result formatting: two decimals, whole numbers as integers, and both ends of the range with decimals when either has them.
  - A date or datetime: `From {min} to {max}.`
  - Anything else: `{n} levels.` (or `{n} levels, compared with {reference}.`) when `levels` is set, else `{n} distinct values.`; then `Most common: {value} ({count}), …` for up to three `top` entries.
  - Except for a level: `{count} rows.`, or `{count} rows, {missing} missing.` when values are missing. Singular `row`, `level`, and `distinct value` when the number is 1.
- The popover opens on click or tap without JavaScript. The JS bundle opens it on mouse hover too, keeps it open after a click, and places it below the button (above when there's no room), within the viewport. Tips are dark on every host: `--fw-embed-tip-bg` and `--fw-embed-tip-fg` theme them.

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

### `code_snippet`

A chunk's code and the text it printed, as a notebook showed them. `data` is the snippet as Framework Cloud normalizes it:

```json
{
  "schema": "framework.artifact/v1",
  "kind": "code_snippet",
  "source": {"language": "r", "file": "notebooks/02-partial-pooling.qmd", "label": "fit-bayes", "lines": [41, 44],
             "permalink": "https://github.com/…/blob/<commit>/notebooks/02-partial-pooling.qmd#L41-L44"},
  "snippet": {"width": 80, "blocks": [
    {"type": "code", "text": "summary(fit)", "lines": [[["fn", "summary"], ["", "(fit)"]]]},
    {"type": "output", "text": "…", "lines": [[["", "…"]]], "folded": true, "truncated": {"lines": 1240}}
  ]},
  "display": {"links": [{"rel": "notebook", "url": "https://…"}, {"rel": "model", "url": "https://…"}]},
  "truncated": true
}
```

- `source.language` is `r`, `python`, or `stata`. `file`, `label`, `lines`, `commit`, `repository`, and `permalink` are optional. Cloud sets `permalink` only for a GitHub repository at a full commit.
- Block `type` is `code`, `output`, `message`, `warning`, or `error`. Every block carries plain `text` and the same text as `lines`: a list of lines, each a list of `[classes, text]` segments. `classes` is a space-separated string, empty for plain text.
- Code classes: `kw`, `str`, `num`, `const`, `fn`, `var`, `op`, `com`. Output classes: `res`, `inp`, and `err` (Stata results, input, and errors), `bold`, `italic`, `underline`, `faint`, and `ansi-black` through `ansi-white`. Cloud builds them; renderers never highlight or parse SMCL or ANSI.
- `folded` (output blocks) shows the block closed. `truncated.lines` counts lines Cloud cut from the end.
- `display.links` holds only links a reader of the embed may open. A model link appears only when the model is embeddable.
- `display.output` is `false` when the output starts hidden. Cloud sets it for `?output=none`. Absent or `true`, output shows.

```html
<div class="fw-embed__snippet">
<div class="fw-embed__snippet-head">
<span class="fw-embed__lang">R</span>
<span class="fw-embed__source">02-partial-pooling.qmd · fit-bayes</span>
<a class="fw-embed__lines" href="{permalink}" target="_blank" rel="noopener">Lines 41–44</a>
<button type="button" class="fw-embed__copy fw-embed__toggle" data-fw-output hidden>Hide output</button>
<button type="button" class="fw-embed__copy" data-fw-copy hidden>Copy</button>
</div>
<pre class="fw-embed__code"><code><span class="fw-t-fn">summary</span>(fit)</code></pre>
<div class="fw-embed__block fw-embed__block--output">
<details class="fw-embed__fold">
<summary>Output, 1259 lines</summary>
<pre class="fw-embed__output"><code>…</code></pre>
<p class="fw-embed__more">1240 more lines not shown.</p>
</details>
</div>
<p class="fw-embed__links"><a href="{url}" target="_blank" rel="noopener">Full notebook</a> <a href="{url}" target="_blank" rel="noopener">Model</a></p>
</div>
```

- The header names the language (`R`, `Python`, `Stata`, else `Code`), then the file's last path segment and the label joined by ` · `, then the line range when `lines` holds two integers with `1 ≤ first ≤ last`: `Line 41` or `Lines 41–44`, numbers formatted as counts (Number formatting: digits group from five digits up). The range links to `permalink` when it is a safe URL.
- A snippet with at least one rendered non-code block gets an output toggle before the copy button. It reads `Hide output`, or `Show output` when `display.output` is `false`. In that case every non-code block also carries `hidden`. The toggle ships `hidden`. The JS bundle shows it, and a click shows or hides every non-code block and swaps the label. Without the bundle, hidden output stays hidden.
- The copy button ships `hidden`. The JS bundle shows it and copies the `textContent` of the snippet's code blocks joined with a newline, without output. Server-rendered markup without the bundle shows no button.
- Each segment becomes a `<span>` whose classes are the segment's tokens prefixed with `fw-t-` (`res bold` → `fw-t-res fw-t-bold`). Tokens outside `[a-z0-9-]` are dropped; a segment with no valid token is plain escaped text. Lines join with `\n` inside `<code>`.
- When `lines` is not a list of lines of two-string segments, the block renders its `text` split on `\n`, unstyled. A block with neither is skipped, as is a block of unknown `type`.
- Every non-code block sits in `<div class="fw-embed__block fw-embed__block--{type}">`. A folded block wraps its output in `<details class="fw-embed__fold">` with `<summary>{Output|Message|Warning|Error}, {n} lines</summary>`, where `n` counts shown and cut lines (`1 line` singular). A cut block ends with `<p class="fw-embed__more">{n} more lines not shown.</p>`.
- Links: `notebook` reads `Full notebook`, `model` reads `Model`. Other `rel` values and unsafe URLs are dropped. The paragraph is left out when no link remains.
- A snippet with no renderable code block renders as `page`.

Code and output use `--fw-embed-code`, a system monospace stack by default (`ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace`), so box-drawing characters in Stata tables share one font and columns stay aligned. Lines do not wrap; a long line scrolls. Output lines sit at 1.45 so Stata's box lines nearly join. Messages are muted, warnings carry an amber rule, and errors a red rule.

### `dag`

A causal diagram with the DAGitty code that defines it. Framework Cloud parses the DAGitty text and lays the graph out once, so renderers write the boxes and edge paths it sends and never compute a layout. `data`:

```json
{
  "schema": "framework.artifact/v1",
  "kind": "dag",
  "dag": {
    "width": 509, "height": 211,
    "nodes": [{"name": "smoking", "label": "Smoking status", "roles": ["exposure"], "x": 8, "y": 173, "width": 142, "height": 30}],
    "edges": [{"from": "smoking", "to": "cancer", "type": "->", "path": "M 152 188 L 361 188", "heads": ["M 369 188 L 360 192.5 L 360 183.5 Z"]}]
  },
  "dagitty": {"text": "dag { … }", "lines": [[["kw", "dag"], ["", " {"]]]},
  "code": {"language": "r", "text": "dag <- dagify(…)", "lines": […]},
  "analysis": {"effect": "total", "exposures": ["smoking"], "outcomes": ["cancer"], "adjustment_sets": [["age"]], "implications": [{"x": "a", "y": "b", "given": ["c"]}]}
}
```

- `dag.width` and `dag.height` are the drawing's size in pixels, integers from 1 to 100,000. Each node's `x`, `y`, `width`, and `height` are integers from 0 to 100,000: its box, top-left corner first. Boxes fit their label at 14px in a monospace font.
- `label` is optional; the name stands in for it. `roles` holds `exposure`, `outcome`, `adjusted`, `latent`, and `selected`; renderers ignore other values.
- An edge's `type` is `->` or `<->`. `path` is an SVG path from box edge to box edge and each entry of `heads` is a closed arrowhead, both matching `^[MLQZ0-9 .-]{1,400}$`. A directed edge has one head and a bidirected edge two.
- `dagitty` and `code` carry `text` and styled `lines`, as code snippet blocks do. `code.language` is `r`, `python`, or `stata`. `code` and `analysis` are optional.
- `analysis` comes from the producer, such as R's dagitty. `adjustment_sets` lists minimal sufficient sets: `[[]]` means no adjustment is needed and `[]` means none exists. `implications` lists testable conditional independencies.

```html
<div class="fw-embed__dag">
<svg class="fw-embed__dag-graph" viewBox="0 0 509 211" width="509" height="211" style="min-width: 381px" role="img" aria-label="{alt}">
<path class="fw-embed__dag-edge" d="M 152 188 L 361 188"/>
<path class="fw-embed__dag-head" d="M 369 188 L 360 192.5 L 360 183.5 Z"/>
<g class="fw-embed__dag-node fw-embed__dag-node--exposure"><rect x="8" y="173" width="142" height="30" rx="6"/><text x="79" y="188">Smoking status</text></g>
</svg>
<p class="fw-embed__dag-legend"><span class="fw-embed__dag-key fw-embed__dag-key--exposure">Exposure</span> …</p>
</div>
<details class="fw-embed__details">
<summary>Description</summary>
<p class="fw-embed__note">{description}</p>
</details>
<details class="fw-embed__details">
<summary>Causal adjustment sets</summary>
<p class="fw-embed__note">Minimal sufficient adjustment sets for the total effect of <code class="fw-embed__var">Smoking status</code> on <code class="fw-embed__var">cancer</code>:</p>
<ul class="fw-embed__dag-list">
<li>{ <code class="fw-embed__var">age</code> }</li>
</ul>
</details>
<details class="fw-embed__details">
<summary>DAGitty specification</summary>
<div class="fw-embed__snippet">
<div class="fw-embed__snippet-head">
<span class="fw-embed__lang">DAGitty</span>
<button type="button" class="fw-embed__copy" data-fw-copy hidden>Copy</button>
</div>
<pre class="fw-embed__code"><code>…</code></pre>
</div>
</details>
<details class="fw-embed__details">
<summary>R code</summary>
…
</details>
```

- A document without `dag`, with an unusable `width` or `height` (or either 0), or with no usable node renders as `page`. A node is usable when `name` is a non-empty string and its four sizes are usable. Unusable edges and heads are skipped.
- The SVG's `aria-label` is `alt`, then `title`, then `Causal diagram`. `min-width` is `floor(width * 3 / 4)` pixels: the drawing shrinks to fit the figure down to three quarters of its size, then scrolls. Edges come first, each path followed by its heads; bidirected edges add `fw-embed__dag-edge--bidirected`. Nodes follow in document order, with a class per role in the order exposure, outcome, adjusted, latent, selected.
- The label shows at most 32 code points. A longer one shows its first 31 with trailing ASCII spaces removed and `…` added, and its full text goes in a `<title>` first in the `<g>`. The text sits at `x + floor(width / 2)`, `y + floor(height / 2)`.
- The legend lists the roles present, in that order: Exposure, Outcome, Adjusted, Latent, Selected. It is left out when no node has a role.
- The disclosures come in this order, and each is left out when empty. A diagram's description shows in the `Description` disclosure, not under the figure; its author stays under the figure.
- `Description` holds `description` as a note.
- The analysis disclosure is named by what it holds: `Causal adjustment sets`, `Testable implications`, or `Adjustment sets and testable implications`. It holds, when `exposures` and `outcomes` are both non-empty lists of strings and `adjustment_sets` is a list: the note naming the effect (`direct` when `effect` is `direct`, else `total`), then `No set of observed variables identifies the effect.` for no usable sets, `No adjustment is needed.` for one empty set, or a list of sets. Sets that aren't lists of strings are skipped. Then, when any `implications` entry has string `x` and `y` and a list of strings `given`, the note `Testable implications:` and a list reading `x ⫫ y`, with ` | given` when `given` is non-empty. Names show as their node's label.
- `DAGitty specification` holds a panel for `dagitty` (`DAGitty`), and `{Language} code` (`R code`, `Python code`, or `Stata code`) one for `code` (`R`, `Python`, or `Stata`). Each panel is drawn from its `lines` as code snippet blocks are, with a copy button the JS bundle shows.

Colors follow DAGitty: exposure green (`--fw-embed-dag-exposure`), outcome blue (`--fw-embed-dag-outcome`), adjusted with a heavy border, latent dashed. Edges use `--fw-embed-muted`.

### `tree`

A project structure: a folder tree with notes on its paths and the `.gitignore` rules that hide some of them. Clients build the tree and never publish ignored or private names; Framework Cloud validates it, adds missing parent folders, and sorts each folder's contents: folders, then files, then ignored groups, each by name ignoring case. Renderers keep that order. `data`:

```json
{
  "schema": "framework.artifact/v1",
  "kind": "tree",
  "tree": {
    "root": "my-project",
    "base": "",
    "method": "git",
    "counts": {"dirs": 6, "files": 3, "hidden": 1539},
    "children": [
      {"name": "R", "path": "R", "type": "dir", "note": "Reusable functions", "open": true, "children": [
        {"name": "clean.R", "path": "R/clean.R", "type": "file"}
      ]},
      {"name": "raw", "path": "data/raw", "type": "dir", "open": false, "ignored": {"rule": "data/raw/", "source": ".gitignore", "line": 4}, "hidden": 12, "children": []},
      {"name": "*.rds", "path": "outputs", "type": "ignored", "ignored": {"rule": "*.rds", "source": ".gitignore", "line": 7}, "hidden": 4},
      {"name": "README.md", "path": "README.md", "type": "file", "note": "Start here", "highlight": true}
    ]
  }
}
```

- `root` is the published folder's name. `base` is its path from the project root, `""` for the root. `method` (`git`, `gitignore`, or `none`) and `counts` are for readers of the document; renderers don't show them.
- A node's `type` is `dir`, `file`, or `ignored`. Every folder has `open` and `children`. A folder with `hidden`, `ignored`, `private: true`, or `depth: true` is a stub: its contents aren't listed and `hidden` counts its files. An `ignored` node is a group of ignored files in the folder `path`, named by its rule without a trailing `/`.
- `note` is plain text up to 500 characters. `highlight: true` marks a row. `ignored` holds `rule`, and optionally `source` (a path relative to the root, `global`, or `info/exclude`) and `line`.
- `hidden` stops at 10,000, which reads `10,000+ files`.

```html
<div class="fw-embed__tree-panel">
<div class="fw-embed__tree" style="--fw-embed-tree-names: 15ch">
<div class="fw-embed__tree-row fw-embed__tree-row--root"><span class="fw-embed__tree-name">my-project/</span></div>
<details class="fw-embed__tree-dir" open>
<summary class="fw-embed__tree-row fw-embed__tree-row--dir"><span class="fw-embed__tree-name"><span class="fw-embed__tree-guide" aria-hidden="true">├─  </span>R/</span><span class="fw-embed__tree-note">Reusable functions</span></summary>
<div class="fw-embed__tree-row fw-embed__tree-row--file"><span class="fw-embed__tree-name"><span class="fw-embed__tree-guide" aria-hidden="true">│   └── </span>clean.R</span></div>
</details>
<div class="fw-embed__tree-row fw-embed__tree-row--dir fw-embed__tree-row--stub"><span class="fw-embed__tree-name"><span class="fw-embed__tree-guide" aria-hidden="true">├── </span>raw/</span><span class="fw-embed__tree-badge" title=".gitignore line 4">ignored · data/raw/ · 12 files</span></div>
<div class="fw-embed__tree-row fw-embed__tree-row--group fw-embed__tree-row--stub"><span class="fw-embed__tree-name"><span class="fw-embed__tree-guide" aria-hidden="true">├── </span>*.rds</span><span class="fw-embed__tree-badge" title=".gitignore line 7">ignored · *.rds · 4 files</span></div>
<div class="fw-embed__tree-row fw-embed__tree-row--file fw-embed__tree-row--highlight"><span class="fw-embed__tree-name"><span class="fw-embed__tree-guide" aria-hidden="true">└── </span>README.md</span><span class="fw-embed__tree-note">Start here</span></div>
</div>
</div>
```

- A document without `tree`, or whose `root` isn't a non-empty string, renders as `page`. A node is usable when it is an object with a non-empty string `name` and a known `type`; an ignored group may instead take its name from its rule. Unusable nodes are skipped.
- The root row reads `root/`. When `base` is set, its folders above `root` (`base` split on `/`, with a last part equal to `root` dropped) lead the name in `fw-embed__tree-crumb`, ending in `/`.
- Each row's guide is the guides of the folders above, `│   ` for a folder with later siblings and four spaces for a last one, then `├── `, or `└── ` for a last row. A folder with usable children is a `<details class="fw-embed__tree-dir">`, open when `open` is `true`, whose `<summary>` is its row and whose rows follow; its guide ends `├─  ` or `└─  `, leaving a cell for the disclosure marker. Other folders, files, and groups are `<div>` rows.
- Row classes name the type (`--dir`, `--file`, `--group`), then `--stub` for stub folders and groups, then `--highlight`. Folder names end in `/`.
- A stub or group carries a badge after its name, joined with ` · `: `private` when private, `ignored` for a group or an ignored folder, the rule, then the file count (`1 file`, `12 files`, `1,520 files`, `10,000+ files`). Its `title` names the rule's source: the source (`global` reads `global excludes file`, `info/exclude` reads `.git/info/exclude`), then `line N` for a positive integer line up to 1,000,000. No title without either.
- A note follows the name, or the badge, in `fw-embed__tree-note`.
- The bordered `fw-embed__tree-panel` holds the tree, and the button when there is one.
- `--fw-embed-tree-names` is the widest root, guide, and name in code points, at most 48, in `ch`. Every name cell is at least that wide, so notes and badges line up. Rows inside closed folders count, so opening one never moves the column.
- Folders deeper than 32 levels show as rows without their contents. Rendering stops after 5,000 rows.
- A long tree starts clipped. When more than 15 rows show at first (the root row, and every row whose folders all start open; rows inside a closed folder don't count) and `data.display.full` isn't `true`, the tree carries `data-fw-embed-clip` and a button follows it inside the panel:

```html
<div class="fw-embed__tree-panel">
<div class="fw-embed__tree" data-fw-embed-clip style="--fw-embed-tree-names: 22ch">
…
</div>
<button type="button" class="fw-embed__more" hidden aria-expanded="false">Show more</button>
</div>
```

- The JS bundle's `enhanceTrees` adds `fw-embed__tree--clipped`, which shows the top 12 rows, the last rows dissolving before the bottom edge. The panel's border stays solid, and the button sits under the rows inside it, unfaded, and shows the button. A click toggles the class, `Show more` and `Show less`, and `aria-expanded`. Without the bundle the whole tree shows and the button stays hidden. Print shows the whole tree.

The tree uses `--fw-embed-code`, so the box-drawing guides join. Guides are faint, folder names bold, stubs and groups muted, and a highlighted row is tinted with `--fw-embed-accent`.

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

When the document has an `image` with a safe `url`, the fallback is that image instead of the paragraph, written exactly as the `image` kind writes it, with `alt` falling back to `title`, then `Chart`:

```html
<div class="fw-embed__chart" data-fw-embed-chart>
<script type="application/json">{spec}</script>
<div class="fw-embed__media"><img class="fw-embed__img fw-embed__img--vector" src="{image.url}" alt="{alt}" loading="lazy" decoding="async" width="672" height="480" style="max-width: min(100%, calc(80vh * 672 / 480))"></div>
</div>
```

`image` is a static rendering of the same chart. It shows wherever the chart does not draw: without JavaScript, in feed readers and email, on sites that do not load Vega, while the chart loads, and when the chart fails or is refused (see "Chart safety"). With `width` and `height`, it holds the chart's space while the chart loads, so the page does not jump. It sits on the same white panel as an `image` figure, since a static chart usually has dark text drawn into it. Print uses the image rather than the drawn chart. An `image` without a safe `url` is ignored and the paragraph fallback is written.

The specification is written exactly as JavaScript's `JSON.stringify` writes it. That means shortest round-trip numbers in ECMAScript `Number::toString` form, integer-like keys first in ascending order, and only `"`, `\`, and control characters escaped. Then `<`, `>`, `&`, U+2028, and U+2029 become `<`, `>`, `&`, ` `, and ` `, so the text cannot close the `<script>` element. The JS bundle draws the chart with vega-embed and moves the fallback paragraph to a screen-reader-only class. While a chart with an image fallback loads, the view is laid out at the container's width but hidden, and the image keeps showing. Once the container's `data-fw-embed-chart-state` is `ready`, the stylesheet hides the image from the screen, keeping its `alt` for screen readers, and the chart sits on the figure's own background.

## Chart safety

Stored specifications render in the host site's origin. Chart renderers must prevent Vega from evaluating arbitrary JavaScript or making network requests:

- **No URLs.** Any specification containing a string `url` property (in data, lookup transforms, or Vega data sources) is not rendered, leaving the fallback image, or the paragraph and link, visible.
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
  - On a dark page, `range.category` is a palette with enough contrast on a dark background: `#8AB4F8`, `#FDBA74`, `#F28B82`, `#81C995`, `#FDD663`, `#C58AF9`, `#78D9EC`, `#FF8BCB`, `#E8EAED`, `#A8C7FA` (phiz's dark theme). It applies only to a categorical color or fill scale without its own `scale.range` or `scale.scheme`. Light pages keep Vega's default palette.

  The specification's own `config` and encodings take precedence over these defaults, so a palette the specification sets is never changed.

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
| Whole number | whole numbers outside decimal columns | `32`, `2026` | `32`, `2026` |
| `p` decimals | everything else, including small numbers | `0.036`, `-5.030`, `0.000` | `0.04`, `-5.03`, `0.00` |
| Grouping | integer parts of five or more digits get commas, at any size | `12,345.679`, `9999`, `1,000,000,000,000,000` | `12,345.68` |
| p-value | `p_value` columns and metrics (also `p.value`, `p-value`, `pvalue`, `Pr(>|t|)`, `Pr(>|z|)`, `Pr(>Chisq)`, `P>|t|`, `P>|z|`, any ASCII case) and test p-values, in both contexts | `< 0.001` below 0.001, else three decimals | the same |
| Percent | confidence levels, in both contexts | `x * 100` rounded to one decimal, with `.0` dropped: `95%`, `97.5%` | the same |

- Values never use scientific notation.
- Large numbers are written in full. Beyond 2^53, digits come from the shortest round-trip form padded with zeros, so `123456789012345678` formats as `123,456,789,012,345,680`.
- Small numbers round like any other: 0.0000006 displays as `0.00` in result formatting.
- A table cell, interval, or statistic whose text stands for a different number than its value (it was rounded, or reads `< 0.001`) gets a `title` attribute with the full value: the shortest round-trip digits, without scientific notation, grouped like the text (`0.0000006`, `-4,031.4812`). An interval's title holds both ends: `0.0000019 to 0.000293`. A value shown exactly gets no title.
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

On an allowed host, these paths are artifact URLs. Fragments and query strings are ignored, except `output=none`. That option asks for a code snippet with its output hidden, and it stays on the matched URL and the URL requested.

| Path | Type |
|---|---|
| `/d/{key}` and `/d/{key}/versions/{n}` | `key` |
| `/@{handle}/{project}/{slug}` and `…/versions/{n}` | `path` |
| `/go/{code}`, a public artifact's short link | `go` |
| `/api/embed/v1/artifacts/{key}` and `…/versions/{n}` | `embed` |

`{key}` is 1 to 64 of `A-Z a-z 0-9 _ -`, and `{code}` 1 to 80 of `A-Z a-z 0-9 -`. Handles, projects, and slugs are 1 to 200 of `A-Z a-z 0-9 _ . -`, and never `.` or `..`. `{n}` is a positive integer of at most nine digits. A trailing slash is allowed on `key` and `path` URLs.

`embed` URLs are requested directly. Other artifact URLs are requested as `{origin}/api/embed/v1/resolve?url={origin}{path}`, with the inner URL percent-encoded. With the option, the inner URL is `{origin}{path}?output=none`, and an `embed` URL is requested as `{origin}{path}?output=none`.

## Theming

The stylesheet themes through these custom properties, set on `.fw-embed` or any ancestor:

| Property | Default | Used for |
|---|---|---|
| `--fw-embed-font` | Unset, so the site's font applies | Labels, headers, prose, and tips |
| `--fw-embed-mono` | JetBrains Mono, then system monospace fonts | Numbers, formulas, the outcome variable, and tip headings |
| `--fw-embed-code` | System monospace fonts | Code and output in code snippets |
| `--fw-embed-fg` | Unset, so the site's text color applies | Embed text, the checked scale, and chart titles |
| `--fw-embed-muted` | The text color at 62% opacity | Table headers, notes, statistic labels, and chart axis labels |
| `--fw-embed-border` | The text color at 18% opacity | Header rules, the scale switch, code snippet panels, link cards, and chart gridlines |
| `--fw-embed-rule` | The text color at 8% opacity | Rules between table rows and between statistics |
| `--fw-embed-bg` | `transparent` | Background of the `<figure class="fw-embed">` wrapper and the scale switch |
| `--fw-embed-accent` | `#1d4ed8`, or `#7aa7ff` in dark mode | Links and focus outlines |
| `--fw-embed-warning` | `#b45309`, or `#f59e0b` in dark mode | Sampling warnings under a Bayesian model's coefficients |
| `--fw-embed-dag-exposure` | `#047857`, or `#34d399` in dark mode | Exposure nodes in a DAG |
| `--fw-embed-dag-outcome` | `#0369a1`, or `#38bdf8` in dark mode | Outcome nodes in a DAG |
| `--fw-embed-tip-bg` | `#1f2229` in both modes | Variable tip background |
| `--fw-embed-tip-fg` | `#f4f5f7` in both modes | Variable tip text |

Muted text, borders, and rules mix the current text color with transparency, so they suit light and dark pages. The accent and warning colors change under `prefers-color-scheme: dark` and under a `data-theme="dark"` or `.dark` ancestor. A `data-theme="light"` or `.light` ancestor keeps the light colors. The defaults are declared with `:where()`, so any host rule that sets a property wins. Image artifacts sit on a white panel in both themes.

Numbers use `--fw-embed-mono`: numeric table cells (`td.fw-embed__num`), numeric statistic values (`dd.fw-embed__num`), and the counts in notes (`span.fw-embed__num`), at 0.92em of the surrounding text with tabular figures. Labels, headers, and prose keep the host font. The default stack starts with JetBrains Mono. The stylesheet declares it with `@font-face`, weights 400 and 600, from `@fontsource/jetbrains-mono` 5.2.8 on jsDelivr, with `font-display: swap`. Its `unicode-range` is Basic Latin plus the no-break space, en and em dashes, and the minus sign. That covers the letters in interval cells ("to") and formulas, which share the mono stack. A site that sets `--fw-embed-mono` to another stack never downloads the font.

Type sizes are relative to the host text, so the host's scale applies. The figure is 0.875em of the surrounding text. Tables and statistics come to about 0.8125em of it, and captions, notes, the meta line, and the `details` summary to about 0.75em. Table rows use a lighter rule than the header. In print, closed `details` open through `::details-content` where supported, and through the JS bundle's `beforeprint` handler elsewhere.
