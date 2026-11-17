=== Framework Embeds ===
Contributors: framework
Tags: statistics, regression, embed, tables, charts
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.3.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Shows Framework artifacts (regression models, tables, hypothesis tests, figures, and Vega-Lite charts) in posts and pages, without iframes.

== Description ==

Paste the URL of a public or unlisted Framework artifact on its own line, and the post shows the artifact as an HTML figure. The figure is static, escaped HTML that your theme's CSS can restyle.

Supported URLs:

* `https://framework.pub/d/{key}` and `https://framework.pub/d/{key}/versions/{n}`
* `https://framework.pub/@{handle}/{project}/{slug}` and `…/versions/{n}`

Three ways to add an artifact:

* Insert the Framework artifact block and enter the URL. This is the preferred way.
* Paste its URL on its own line, in the block editor or the classic editor.
* Use the shortcode `[framework_artifact url="https://framework.pub/d/…"]`.

= Stored copies (the block) =

The block fetches the artifact once, when you enter the URL, and stores the embed document in the post with the version it pinned. Pages render from that copy and never contact the server. In the block toolbar and sidebar, "Fetch again" re-fetches the same version, and "Use newest version" appears when a newer version exists. Either one shows the new copy and replaces the stored one only when you choose "Replace stored copy".

The copy lives in the block's attributes in the post content, so it is saved, revised, copied, and exported with the post. A large table or chart makes the post content larger by the size of its embed document.

= Live embeds (pasted URLs and the shortcode) =

Pasted URLs and the shortcode have nowhere to store a copy, so they render from a cache. The first view shows a plain link and queues a background fetch (Action Scheduler when installed, else WP-Cron). Later views show the figure. When a cached copy passes its `Cache-Control` max-age, it still renders while a background job refreshes it. A `404` drops the cached copy and the link stays. Page rendering never waits on the network.

= Takedown check =

Once a day, the plugin asks the server about each stored copy on an allowed host. It removes a copy only when the server answers `404` or `410`, and a link takes its place. Timeouts and server errors change nothing. Turn the check off under Settings → Framework embeds. It is on by default.

= Images =

Image artifacts render as `<img>` pointing at the image URL in the embed document. No file is copied to your site.

= External service =

The plugin requests embed documents from framework.pub, and from any self-hosted Framework Cloud server an administrator adds under Settings → Framework embeds. It contacts no other host. A request sends the artifact URL and your site's address in the user agent. No cookies are sent.

Requests go to `https://framework.pub/api/embed/v1/resolve` and `https://framework.pub/api/embed/v1/artifacts/…`: when an editor inserts or re-fetches a block, from background jobs for pasted URLs, and from the daily takedown check. Cached copies for pasted URLs count as fresh for as long as the server's `Cache-Control` header allows, and for one hour at most.

Charts load vega, vega-lite, and vega-embed from cdn.jsdelivr.net, with subresource integrity, only on pages that contain a chart.

= Missing artifacts =

When an artifact has no copy to show, visitors see a link to it. People who can edit posts also see a notice that explains why.

= Filters =

* `framework_embed_hosts`: the allowed hosts.
* `framework_embed_max_ttl`: the longest time a cached copy counts as fresh, in seconds. Default 3600.
* `framework_embed_fetcher`: a callable that takes an embed API URL and returns a `Framework\Embed\Http\Response`. It must not follow redirects.

== Installation ==

1. Upload the `framework-embed` folder to `/wp-content/plugins/`, or upload the zip under Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. To embed from a self-hosted Framework Cloud server, add its host under Settings → Framework embeds.

== Frequently Asked Questions ==

= Which artifacts can be embedded? =

Public and unlisted artifacts. Private and password-protected artifacts never render.

= Can I restyle the figures? =

Yes. The stylesheet uses custom properties such as `--fw-embed-font`, `--fw-embed-accent`, and `--fw-embed-border`. Set them in your theme's CSS.

= Which hosts can I add? =

A host name, such as `framework.example.org`, allows HTTPS only. An origin, such as `http://localhost:8000`, allows exactly that scheme, host, and port. IP addresses and paths are rejected.

== Changelog ==

= 0.3.2 =
* One coefficients table with estimates, ratios, standard errors, p-values, and intervals. Model details hold only fit statistics and other tables.
* Stata commands appear in the formula slot.
* Long text statistics take a full row; labels no longer break letter by letter.
* Models go by their common names, such as Logistic regression and Ordinary least squares regression.

= 0.3.1 =
* No scientific notation: tiny values read "< 0.01".
* Numbers set in JetBrains Mono.

= 0.3.0 =
* Models show estimates at two decimals; diagnostics sit under Model details.
* Smaller, roomier type that sits well in blog posts.
* Live Vega-Lite charts follow the page's theme, width, and font.

= 0.2.0 =
* The block stores the artifact with the post. Fetch again, Use newest version, and a daily takedown check.
* Pasted URLs and the shortcode render from a background-filled cache and never wait on the network.

= 0.1.0 =
* First release.
