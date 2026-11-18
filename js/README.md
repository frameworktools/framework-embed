# @framework-cloud/embed

`@framework-cloud/embed` renders Framework Cloud embed documents (`framework.embed/v1`) as static HTML with no npm dependencies. The package includes a `<framework-artifact>` custom element, a lazy Vega-Lite chart loader, and a stylesheet. It is not published to npm. Releases attach the built files, and `php/assets/` holds the same files.

## Builds

| File | Purpose |
|---|---|
| `dist/framework-embed.js` | ES module. Importing it registers no custom element and adds no event listeners. |
| `dist/framework-embed.iife.js` | Script-tag build. It sets `window.FrameworkEmbed`, applies `window.FrameworkEmbedConfig`, registers `<framework-artifact>`, calls `expandDetailsForPrint()`, and runs `enhanceCharts`, `enhanceTips`, `enhanceCopy`, and `enhanceOutput` on `document` once the document is parsed. |
| `dist/framework-embed.css` | Stylesheet. |

## Rendering

- `render(doc)` returns escaped HTML without side effects. The PHP `Renderer::render` returns the same bytes.
- `mount(element, doc, {lazy})` sets the content of an element in the document to `render(doc)`, calls `enhanceCharts`, `enhanceTips`, `enhanceCopy`, and `enhanceOutput` on it, and returns the rendered `<figure>`.
- `fetchEmbed(url, {hosts, fetch, signal})` resolves an artifact URL on an allowed host. It sends no cookies and follows no redirects. It returns `null` when the server responds with `404` or `410`. It throws `EmbedError` with `code` `not_allowed` for a URL that is not allowed, `http` for other error statuses, and `invalid` for a response that is not an embed document. Network failures, redirects, aborts, and malformed JSON reject with the error `fetch` or `response.json()` raises.

## Page setup

- `configure({hosts, vegaScripts, vegaEmbed, vegaInterpreter, vegaOptions})` sets defaults for `fetchEmbed`, the custom element, and the chart loader, and returns the resulting configuration.
  - `hosts`: allowed hosts, which replace the default `["framework.pub"]`.
  - `vegaScripts`: the scripts that provide `vega`, `vega-lite`, and `vega-embed`, as URLs or `{src, integrity}` objects. The default loads `vega` 6.2.0, `vega-lite` 6.4.3, and `vega-embed` 7.1.0 from jsDelivr with subresource integrity.
  - `vegaEmbed`: a `vega-embed` function to use instead of loading scripts.
  - `vegaInterpreter`: a Vega expression interpreter to use instead of the one in the `vega-embed` build.
  - `vegaOptions`: extra `vega-embed` options, such as `renderer` or `defaultStyle`. The safety options in "Chart safety" in `SPEC.md` override them.
- `enhanceCharts(root, {lazy})` draws every `[data-fw-embed-chart]` under `root` that has not been drawn, each as it comes within 200 pixels of the viewport, and returns the number found. `{lazy: false}` draws them at once. The first chart loads the Vega scripts. Each chart container gets `data-fw-embed-chart-state`: `waiting`, `loading`, `ready`, or `error`. A chart in the `error` state shows its fallback link.
- `enhanceTips(root)` gives each variable or column tip under `root` a unique `id` when the same artifact appears more than once on a page, updating its button's `popovertarget`. The first call also adds listeners on `document` and `window` that open tips on mouse hover, keep a clicked tip open, place an open tip beside its button, follow scrolling, and close tips on resize. Call it after the markup is in the document.
- `enhanceCopy(root)` shows the hidden `Copy code` button on each code snippet under `root`. A click copies the snippet's code blocks, joined with newlines, without output. The button reads `Copied` or `Copy failed` for 2 seconds. The Clipboard API requires a secure context: HTTPS or `localhost`.
- `enhanceOutput(root)` shows the hidden output toggle on each code snippet under `root` that has non-code blocks. A click hides or shows its output, messages, warnings, and errors, and switches the label between `Hide output` and `Show output`.
- `expandDetailsForPrint()` opens closed `.fw-embed__details` and `.fw-embed__fold` disclosures before printing and closes them afterward. Later calls do nothing.
- `defineElement(name)` registers the custom element, `framework-artifact` by default, and returns `false` when the name is taken.

`<framework-artifact src="…">` renders a stored document from a direct child `<script type="application/json">` without making a network request. Otherwise it fetches `src` and keeps its children visible until the document loads. It sets `data-fw-embed-state` to `loading`, `ready`, `gone` (the server returned `404` or `410`), or `error`, and dispatches `framework-embed:load` with `detail.doc` or `framework-embed:error` with `detail.error`.

## Other exports

- `matchArtifactUrl`, `isAllowedUrl`, `allowedOrigin`, and `embedRequestUrl` validate and resolve URLs against the allowed hosts.
- `formatNumber`, `formatValue`, `formatPValue`, and `formatPercent` apply the number formatting rules in `SPEC.md`.
- `escapeHtml`, `safeUrl`, and `scriptJson` are the escaping functions the renderer uses.
- `chartSpec`, `chartOptions`, `chartTheme`, `blockingLoader`, and `textTooltip` are the pieces the chart loader passes to `vega-embed`.

`js/index.d.ts` declares every export.

Charts use Vega's expression interpreter, a loader that blocks external URLs, and text-only tooltips. See "Chart safety" in `SPEC.md`. Charts inherit their theme, font, and colors from the page, fill the figure width, and redraw when the page switches between light and dark themes. See "Chart display" in `SPEC.md`.
