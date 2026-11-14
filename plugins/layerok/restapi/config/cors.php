<?php

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Readable from JS on the city subdomains (cross-origin), e.g.
    // response.headers['x-catalog-cache'] in axios.
    'exposed_headers' => ['X-Catalog-Cache'],

    'max_age' => 0,

    'supports_credentials' => true,

];
