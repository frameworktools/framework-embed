# framework-cloud/embed

Fetches Framework Cloud embed documents (`framework.embed/v1`) and renders them as static HTML. It requires PHP 8.1 or later and has no runtime dependencies.

## Classes

| Class | Purpose |
|---|---|
| `Client` | Resolves artifact URLs on allowed hosts, caches documents for their `Cache-Control` lifetime, and returns `null` on `404` |
| `UrlMatcher` | Applies the host allow-list and recognizes `/d/{key}`, `/@handle/project/slug`, their `/versions/{n}` forms, and embed API URLs |
| `Renderer` | `render(array $doc)` and `renderJson(string $json)` return the same HTML as the JS renderer |
| `Document` | `decode(string $json)` keeps empty JSON objects in Vega-Lite specifications |
| `Assets` | Paths and a version hash for the CSS and JS copied from the JS build |
| `Cache`, `ArrayCache`, `Psr16Cache` | The cache interface, an in-memory cache, and a PSR-16 adapter |
| `Http\StreamFetcher` | The default fetcher. It uses curl or PHP streams, never follows redirects, and caps responses at 16 MB. |

## Fetchers

A fetcher is a callable that takes a URL and returns an `Http\Response` or an array with `status`, `headers`, and `body`. It must not follow redirects. WordPress example:

```php
$client = new Client(fetcher: function (string $url): Response {
    $answer = wp_remote_get($url, ['redirection' => 0, 'timeout' => 5]);
    return new Response((int) wp_remote_retrieve_response_code($answer), wp_remote_retrieve_headers($answer)->getAll(), wp_remote_retrieve_body($answer));
});
```

Pass a `Renderer::render` result through the site's output without filters that rewrite HTML. Filters such as `wpautop` can break the chart's JSON element.
