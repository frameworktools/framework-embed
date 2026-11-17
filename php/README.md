# framework-cloud/embed

`framework-cloud/embed` fetches Framework Cloud embed documents (`framework.embed/v1`) and renders them as static HTML. It requires PHP 8.1 or later and has no runtime dependencies.

## Classes

| Class | Purpose |
|---|---|
| `Client` | Resolves artifact URLs on allowed hosts, caches documents for their `Cache-Control` lifetime, and returns `null` on `404` or `410` |
| `UrlMatcher` | Applies the host allow-list and recognizes `/d/{key}`, `/@handle/project/slug`, their `/versions/{n}` forms, and embed API URLs |
| `Renderer` | `render(array $doc)` and `renderJson(string $json)` return the same HTML as the JavaScript renderer |
| `Document` | `decode(string $json)` preserves empty JSON objects in Vega-Lite specifications |
| `Assets` | Returns filesystem paths and a version hash for the bundled CSS and JavaScript files |
| `Cache`, `ArrayCache`, `Psr16Cache` | Cache interface, in-memory cache, and PSR-16 adapter |
| `Http\StreamFetcher` | Default HTTP fetcher using curl or PHP streams, with redirects disabled and a 16 MB response limit |

## Fetchers

A fetcher is a callable that accepts a URL and returns an `Http\Response` or an array with `status`, `headers`, and `body`. Fetchers must disable redirects. In WordPress, wrap `wp_remote_get`:

```php
$client = new Client(fetcher: function (string $url): Response {
    $answer = wp_remote_get($url, ['redirection' => 0, 'timeout' => 5]);
    return new Response((int) wp_remote_retrieve_response_code($answer), wp_remote_retrieve_headers($answer)->getAll(), wp_remote_retrieve_body($answer));
});
```

Output `Renderer::render` markup directly without running HTML formatting filters. Filters such as `wpautop` can corrupt the chart's inline JSON `<script>` element.
