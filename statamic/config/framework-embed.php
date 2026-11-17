<?php

return [

    /*
    | Hosts embed documents are fetched from, besides framework.pub. A host
    | name such as framework.example.org allows HTTPS only. An origin such as
    | http://localhost:8000 allows exactly that scheme, host, and port. IP
    | addresses, paths, and user info are ignored, and logged as a warning.
    */
    'hosts' => array_filter(explode(',', (string) env('FRAMEWORK_EMBED_HOSTS', ''))),

    /*
    | The longest time a cached live embed counts as fresh, in seconds. The
    | server's Cache-Control header can only shorten it. Expired copies still
    | render for up to a week while they are refreshed in the background.
    */
    'max_ttl' => (int) env('FRAMEWORK_EMBED_MAX_TTL', 3600),

    /*
    | Seconds to wait for a Framework server before rendering a plain link.
    */
    'timeout' => 5,

    /*
    | How live embeds ({{ framework:artifact url="…" }} and the
    | framework_embeds modifier) are fetched in the background:
    | 'after_response' runs the fetch after the response is sent;
    | 'queue' pushes it onto the default queue.
    */
    'refresh' => env('FRAMEWORK_EMBED_REFRESH', 'after_response'),

    /*
    | Daily check that removes stored copies (framework_artifact fields) whose
    | artifact the server reports gone with a 404 or 410. Timeouts and server
    | errors change nothing. Needs Laravel's scheduler (schedule:run).
    */
    'takedown' => (bool) env('FRAMEWORK_EMBED_TAKEDOWN', true),

    /*
    | The cache store for live embed documents. Null uses the default store.
    */
    'cache_store' => env('FRAMEWORK_EMBED_CACHE_STORE'),

];
