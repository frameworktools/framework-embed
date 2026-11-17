<?php

return [

    /*
    | Additional Framework Cloud hosts allowed for embeds alongside
    | framework.pub. A host name such as framework.example.org allows HTTPS on
    | the default port. An origin such as http://localhost:8000 allows that
    | exact scheme, host, and port. IP addresses, paths, and user info are
    | ignored and logged as warnings.
    */
    'hosts' => array_filter(explode(',', (string) env('FRAMEWORK_EMBED_HOSTS', ''))),

    /*
    | Maximum time in seconds that a cached live embed counts as fresh. Shorter
    | Cache-Control max-age headers from the server take precedence. Expired
    | copies continue rendering for up to seven days while refreshing in the
    | background.
    */
    'max_ttl' => (int) env('FRAMEWORK_EMBED_MAX_TTL', 3600),

    /*
    | Request timeout in seconds when contacting a Framework Cloud server.
    */
    'timeout' => 5,

    /*
    | Background refresh mode for live embeds ({{ framework:artifact url="…" }}
    | and the framework_embeds modifier). 'after_response' runs after the HTTP
    | response is sent. 'queue' dispatches onto the default Laravel queue.
    */
    'refresh' => env('FRAMEWORK_EMBED_REFRESH', 'after_response'),

    /*
    | Daily check that removes stored copies in framework_artifact fields when
    | the server returns 404 or 410. Timeouts and server errors leave stored
    | copies unchanged. Requires the Laravel scheduler (schedule:run).
    */
    'takedown' => (bool) env('FRAMEWORK_EMBED_TAKEDOWN', true),

    /*
    | Laravel cache store for live embed documents. Null uses the default store.
    */
    'cache_store' => env('FRAMEWORK_EMBED_CACHE_STORE'),

];
