=== Framework Embeds ===
Contributors: frameworktools
Tags: statistics, regression, embed, tables, charts
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.8.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Embeds published Framework models, tables, hypothesis tests, DAGs, figures, and Vega-Lite charts in WordPress posts and pages.

== Description ==

Paste the URL of a public or unlisted Framework artifact on its own line, or insert the **Framework artifact** block, to display the result in a post or page. Models, tables, hypothesis tests, DAGs, figures, and Vega-Lite charts render as escaped HTML styled to match your theme.

Supported URLs:

* `https://framework.pub/d/{key}` and `https://framework.pub/d/{key}/versions/{n}`
* `https://framework.pub/@{handle}/{project}/{slug}` and `…/versions/{n}`

You can embed an artifact in three ways:

* Insert the **Framework artifact** block and enter the artifact URL to store a copy of that version with the post.
* Paste the artifact URL on its own line in the block editor or classic editor.
* Add the shortcode `[framework_artifact url="https://framework.pub/d/…"]`.

= Stored embeds =

The **Framework artifact** block fetches the artifact once when you enter its URL and saves the embed document in the post, pinned to that version. Published pages render from the saved copy without contacting the server. In the block toolbar and sidebar, **Fetch again** re-fetches the pinned version, and **Use newest version** appears when a newer version exists. Both preview the updated copy and replace the saved copy only when you select **Replace stored copy**.

The saved copy is stored in the block's attributes in post content, so revisions, duplicates, and exports include it. Large tables or charts increase post content size by the size of their embed document.

= Live embeds =

Pasted URLs and the shortcode render from a transient cache. The first view displays a plain link and queues a background fetch through Action Scheduler when installed, or WP-Cron otherwise. Subsequent views render the embedded output. When a cached copy passes its `Cache-Control` lifetime, it continues rendering while a background job refreshes it. A `404` or `410` response removes the cached copy and restores the link. Page rendering never waits on network requests.

= Takedown check =

Once a day, the plugin checks each stored artifact on an allowed host. If the server responds with `404` or `410`, the plugin removes the stored copy and displays a link instead. Timeouts and server errors leave stored copies unchanged. Disable this check under **Settings → Framework embeds** if needed.

= Figure images =

Image artifacts render as `<img>` elements pointing to the image URL in the embed document. No image files are copied into the WordPress media library.

= External services =

The plugin requests embed documents from framework.pub and from any self-hosted Framework Cloud servers configured under **Settings → Framework embeds**. Requests send the artifact URL and your site address in the `User-Agent` header without cookies.

Requests call `/api/embed/v1/resolve` and `/api/embed/v1/artifacts/…` when an editor inserts or refreshes a block, during background refreshes for pasted URLs, and during the daily takedown check. Cached copies for pasted URLs remain fresh for the server's `Cache-Control` lifetime, capped at one hour by default.

Figure images load in visitors' browsers from the image URL in the embed document. On framework.pub, that URL redirects to `content.framework.pub`.

framework.pub is run by Framework. See its [terms of service](https://framework.pub/terms) and [privacy policy](https://framework.pub/privacy). Self-hosted servers are run by their own operators.

= Source code and bundled libraries =

The plugin's source code, including the unminified renderer and its build tools, is at [github.com/frameworktools/framework-embed](https://github.com/frameworktools/framework-embed). `vendor-embed/assets/framework-embed.js` is the unminified build of `framework-embed.iife.js`. Run `make wordpress` in the repository to build the plugin.

The plugin bundles these libraries:

* [Vega](https://github.com/vega/vega) 6.2.0, [Vega-Lite](https://github.com/vega/vega-lite) 6.4.3, and [vega-embed](https://github.com/vega/vega-embed) 7.1.0, BSD 3-Clause License. They draw Vega-Lite charts and load only on pages with a chart.
* [JetBrains Mono](https://github.com/JetBrains/JetBrainsMono) through [Fontsource](https://github.com/fontsource/font-files) 5.2.8, SIL Open Font License 1.1. It sets numbers in tables and models.

= Missing artifacts =

When an artifact has no copy to render, visitors see a plain link. Users who can edit posts also see a notice explaining why the embed is unavailable.

= PHP filters =

* `framework_embed_hosts`: array of allowed host names and origins.
* `framework_embed_max_ttl`: maximum cache lifetime in seconds (default `3600`).
* `framework_embed_fetcher`: callable accepting an embed API URL and returning `Framework\Embed\Http\Response` without following redirects.

== Installation ==

1. Upload the `framework-embed` folder to `/wp-content/plugins/`, or upload the `.zip` archive under **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. To allow embeds from a self-hosted Framework Cloud server, add its host under **Settings → Framework embeds**.

== Frequently Asked Questions ==

= Which artifacts can be embedded? =

Public and unlisted artifacts stored on Framework Cloud without a password can be embedded. Private artifacts, password-protected artifacts, and external publications cannot be embedded.

= Can I customize the styling? =

Yes. Override custom properties such as `--fw-embed-font`, `--fw-embed-mono`, `--fw-embed-accent`, and `--fw-embed-border` in your theme's stylesheet.

= Which server hosts are accepted? =

A bare host name such as `framework.example.org` allows HTTPS on the default port. An origin such as `http://localhost:8000` allows that exact scheme, host, and port. IP addresses and URL paths are rejected.

== Changelog ==

= 0.8.2 =
* An artifact's owner can turn off the link back to its Framework server on Framework Cloud.

= 0.8.1 =
* A DAG's description, adjustment sets, DAGitty specification, and code each open in a section of their own, named for what they hold.

= 0.8.0 =
* Embeds follow the display settings the artifact's owner chooses on Framework Cloud, such as a model without its details or a DAG without its legend.

= 0.7.0 =
* DAGs: causal diagrams drawn as SVG, with exposure, outcome, adjusted, and latent variables marked. The DAGitty code and the code that built the graph sit under Code with copy buttons, and adjustment sets and testable implications under Analysis.

= 0.6.0 =
* A chart published with a static image, such as a ggplot, shows that image until the chart draws, and keeps it wherever the chart can't draw: without JavaScript, in feed readers and email, and in print.
* On a dark page, a chart that doesn't set its own colors uses a palette made for dark backgrounds.

= 0.5.1 =
* A page whose only embed is a code snippet loads the script, so the copy button and Show output button appear.

= 0.5.0 =
* Code snippets: a chunk's code with syntax colors, and the text it printed. Long output folds, and a button copies the code.
* A Show output button hides or shows a snippet's output. Add ?output=none to a snippet's link to start with the output hidden.

= 0.4.1 =
* Numbers round to two decimals in results and three in data tables, however small: 0.0000006 shows as 0.00. Hover a rounded number to see its full value.
* A Bayesian model's interval column is headed 95% interval.

= 0.4.0 =
* Bayesian models from brms, rstanarm, Bambi, PyMC, and Stata's bayes and bayesmh show their posterior: posterior mean and SD, credible interval, R-hat, and effective sample sizes. Odds and rate ratios are summaries of the exponentiated draws. Warnings flag R-hat above 1.01 and divergent transitions, and Model details lists the priors and sampling settings.

= 0.3.8 =
* The outcome in Model details is set as a variable name, in the code font and the text color.

= 0.3.7 =
* Model details says Outcome instead of Response, and the formula sits under the model line instead of overlapping it.

= 0.3.6 =
* The plugin's script loads on pages whose embeds have variable tips, not only charts, so tips open on hover and sit beside their term.

= 0.3.5 =
* The title and the link back head each embed, over a light rule. The description and author follow the content.
* A model's type, response, and formula are in Model details. The coefficients come first.
* Model terms and table columns explain themselves on hover or tap: the variable's label, the level a coefficient compares against, and a summary of its data, when the artifact carries them.
* Vega, Vega-Lite, vega-embed, and JetBrains Mono are bundled with the plugin. Pages no longer load them from a CDN.
* The readme links the framework.pub terms of service and privacy policy, and names the bundled libraries and the plugin's source code.

= 0.3.4 =
* Clearer wording in the block, the settings page, and the documentation.

= 0.3.3 =
* SVG figures fill the width of the embed, and tall figures stop at 80 percent of the window height.
* The white panel behind figures is thinner.
* Plainer wording in the embeds, the plugin, and its documentation.
* The plugin URI points to the correct repository.

= 0.3.2 =
* Regression models combine estimates, exponentiated ratios, standard errors, test statistics, p-values, and confidence intervals into one coefficient table, leaving fit statistics and additional tables in Model details.
* Stata models without a formula display the estimation command in the formula slot.
* Text statistics longer than 24 characters span a full row, and labels wrap only between words.
* Model headers display standard statistical names such as Logistic regression and Ordinary least squares regression.

= 0.3.1 =
* Numeric cells and statistics format without scientific notation, displaying "< 0.01" or "> -0.01" for nonzero model and test values that round to zero.
* Numeric cells and statistics use JetBrains Mono.

= 0.3.0 =
* Models and hypothesis tests round estimates to two decimal places and place diagnostics in collapsed Model details and Test details panels.
* Embed typography scales in em units relative to the host page.
* Vega-Lite charts match the host page's theme, container width, and font.

= 0.2.0 =
* The Framework artifact block saves a copy of the artifact with the post and adds Fetch again, Use newest version, and a daily takedown check.
* Pasted URLs and the shortcode render from a background cache without delaying page rendering.

= 0.1.0 =
* Initial release.
