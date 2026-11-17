# @framework-cloud/embed

`@framework-cloud/embed` renders Framework Cloud embed documents (`framework.embed/v1`) as static HTML with no npm dependencies. The package includes a `<framework-artifact>` custom element, a lazy Vega-Lite chart loader, and a stylesheet.

## Builds

| File | Purpose |
|---|---|
| `dist/framework-embed.js` | ES module |
| `dist/framework-embed.iife.js` | Browser script tag that sets `window.FrameworkEmbed`, defines `<framework-artifact>`, and initializes charts on load |
| `dist/framework-embed.css` | Stylesheet |

## API

- `render(doc)` returns escaped HTML without side effects.
- `mount(element, doc)` renders HTML into an element and draws any charts inside it.
- `fetchEmbed(url, {hosts, fetch, signal})` resolves an artifact URL on an allowed host and returns `null` when the server responds with `404` or `410`.
- `configure({hosts, vegaScripts, vegaEmbed, vegaOptions})` sets defaults for the custom element and chart loader.
- `enhanceCharts(root)` draws every `[data-fw-embed-chart]` under `root` as it nears the viewport.
- `matchArtifactUrl`, `isAllowedUrl`, and `embedRequestUrl` validate and resolve URLs against the host allow-list.
- `defineElement(name)` registers the custom element.

`vega-embed` 7.1.0, `vega` 6.2.0, and `vega-lite` 6.4.3 load from jsDelivr with subresource integrity only when a page contains a chart. Pass `vegaScripts` to load them from self-hosted files, or pass `vegaEmbed` to supply the function directly. Charts use Vega's expression interpreter, a loader that blocks external URLs, and text-only tooltips. See "Chart safety" in `SPEC.md`.

Charts inherit their theme, font, and colors from the page, fill the figure width, and redraw when the page switches between light and dark themes. See "Chart display" in `SPEC.md`.

`<framework-artifact>` renders a stored document from a child `<script type="application/json">` without making a network request.

See `SPEC.md` for markup, number formatting, and allowed-host specifications.
