<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://edu.klassci.com'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
    | Le front de production est sur une autre origine que l'API : le navigateur
    | n'y laisse lire à JavaScript que les en-têtes nommés ici, en plus des
    | en-têtes CORS-safelisted. Ceux-ci portent des VALEURS : le délai d'un 503
    | KLASSCI ou d'un 429, et le quota d'un seau (frontend_lms#426). La classe
    | d'une erreur, elle, est dans le corps (`reason`, ADR-906-01).
    */
    'exposed_headers' => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],

    'max_age' => 0,

    'supports_credentials' => false,

];
