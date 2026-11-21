<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The SPA and the API are published under the same domain in production, so
| no cross-origin request is needed and the allowlist stays empty. If the SPA
| is ever hosted on a different origin, list it in CORS_ALLOWED_ORIGINS
| (comma separated) together with its scheme, for example:
|
|     CORS_ALLOWED_ORIGINS=https://app.example.com
|
| Credentials are enabled because Sanctum authenticates with a session cookie,
| which means a wildcard origin is never valid here.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)));

return [

    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
