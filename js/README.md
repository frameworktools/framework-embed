# @framework-cloud/embed

Renders Framework Cloud embed documents (`framework.embed/v1`) as static HTML, with no runtime dependencies. The package includes a `<framework-artifact>` custom element, a lazy Vega-Lite chart loader, and a stylesheet.

## Builds

| File | Use |
|---|---|
| `dist/framework-embed.js` | ES module |
| `dist/framework-embed.iife.js` | Script tag. It sets `window.FrameworkEmbed`, defines `<framework-artifact>`, and draws charts on load. |
| `dist/framework-embed.css` | Stylesheet |

## API

- `render(doc)` returns escaped HTML. It is pure.
- `mount(element, doc)` renders into an element and draws its charts.
- `fetchEmbed(url, {hosts, fetch, signal})` resolves an artifact URL on an allowed host. It returns `null` when the server answers `404` or `410`.
- `configure({hosts, vegaScripts, vegaEmbed, vegaOptions})` sets defaults for the element and the chart loader.
- `enhanceCharts(root)` draws every `[data-fw-embed-chart]` under `root` once it nears the viewport.
- `matchArtifactUrl`, `isAllowedUrl`, and `embedRequestUrl` apply the host allow-list.
- `defineElement(name)` registers the custom element.

vega-embed 7.1.0, vega 6.2.0, and vega-lite 6.4.3 load from jsDelivr with subresource integrity, and only when a page contains a chart. Pass `vegaScripts` to load them from the site itself, or `vegaEmbed` to supply the function directly. Charts use vega's expression interpreter, a loader that refuses every URL, and text-only tooltips. `SPEC.md`, "Chart safety", lists the rules.

Charts take their theme, font, and colors from the page and fill the figure's width. They re-draw when the site switches themes. `SPEC.md`, "Chart display", has the details.

`<framework-artifact>` renders a stored document from a child `<script type="application/json">` without fetching.

The rules for markup, number formatting, and allowed hosts are in the repository's `SPEC.md`.
