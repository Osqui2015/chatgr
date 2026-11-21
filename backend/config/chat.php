<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chat rate limits
    |--------------------------------------------------------------------------
    |
    | Maximum number of requests allowed per window for the endpoints that are
    | most exposed to abuse. Every value can be tuned per environment.
    |
    */

    'rate_limits' => [
        'login' => (int) env('RATE_LIMIT_LOGIN', 10),
        'api' => (int) env('RATE_LIMIT_API', 120),
        'upload' => (int) env('RATE_LIMIT_UPLOAD', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat behaviour
    |--------------------------------------------------------------------------
    |
    | Tunable limits that describe the behaviour of the chat itself.
    |
    */

    'message' => [
        'max_length' => 4000,
        'max_file_size' => 20 * 1024 * 1024,
        'page_size' => 50,
        'max_page_size' => 100,
    ],

    'presence' => [
        'ttl_seconds' => 120,
        'typing_ttl_seconds' => 6,
    ],

    'ephemeral' => [
        'ttl_hours' => 24,
    ],

    'avatar' => [
        'sizes' => [32, 64, 128, 512],
        'quality' => 82,
    ],

];
