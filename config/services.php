<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | KLASSCI API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration pour l'intégration avec l'API KLASSCI
    | Le service KlassciProxyService utilise ces paramètres
    |
    */
    'klassci' => [
        'url' => env('KLASSCI_API_URL', 'http://presentation.klassci.com/api/lms'),
        'token' => env('KLASSCI_API_TOKEN'),
        'cache_ttl' => env('KLASSCI_CACHE_TTL', 300),
        'timeout' => env('KLASSCI_TIMEOUT', 30),
        'ssl_verify' => env('KLASSCI_SSL_VERIFY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | ConvertAPI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration pour ConvertAPI - Service de conversion de documents
    | Utilisé pour convertir PowerPoint, Word, PDF en images
    | Documentation: https://www.convertapi.com/doc/php
    |
    */
    'convertapi' => [
        'secret' => env('CONVERTAPI_SECRET'),
    ],

];
