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
        'url' => env('KLASSCI_API_URL'),
        'token' => env('KLASSCI_API_TOKEN'),
        'cache_ttl' => env('KLASSCI_CACHE_TTL', 300),
        'connect_timeout' => env('KLASSCI_CONNECT_TIMEOUT', 2),
        'timeout' => env('KLASSCI_TIMEOUT', 5),
        'retry_after' => env('KLASSCI_RETRY_AFTER', 30),
        'circuit_breaker_enabled' => env('KLASSCI_CIRCUIT_BREAKER_ENABLED', true),
        'circuit_breaker_failures' => env('KLASSCI_CIRCUIT_BREAKER_FAILURES', 3),
        'circuit_breaker_cooldown' => env('KLASSCI_CIRCUIT_BREAKER_COOLDOWN', 30),
        'circuit_breaker_window' => env('KLASSCI_CIRCUIT_BREAKER_WINDOW', 60),
        'ssl_verify' => env('KLASSCI_SSL_VERIFY', true),

        // PERF-02 (issue #137) — Memoization intra-request + cache user-token-aware + Http::pool batch.
        // Voir .claude/specs/perf-02-klassci-batch-cache/design.md §2-4.
        'pool_size' => env('KLASSCI_POOL_SIZE', 4),
        'user_token_cache_default_ttl' => env('KLASSCI_USER_TOKEN_TTL', 300),
        'memoize_enabled' => env('KLASSCI_MEMOIZE_ENABLED', true),
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

    'visio' => [
        'webhook_secret' => env('VISIO_RECORDING_WEBHOOK_SECRET'),
        'webhook_max_age' => (int) env('VISIO_RECORDING_WEBHOOK_MAX_AGE', 300),

        // Racine des enregistrements produits par Jibri, montee en LECTURE
        // SEULE depuis le serveur visio (#469). Le media ne transite jamais en
        // HTTP : le webhook ne porte que des metadonnees, et c'est le LMS qui
        // lit le fichier ici pour se l'approprier.
        //
        // AUCUN DEFAUT, volontairement. Absente, la voie Jibri du webhook reste
        // inactive : mieux vaut une fonctionnalite eteinte qu'un chemin serveur
        // devine, qui ferait lire n'importe quel repertoire au job d'import.
        'recordings_root' => env('VISIO_RECORDINGS_ROOT'),

        // Acces aux salles Jitsi. Le serveur tourne avec ENABLE_AUTH=1 et
        // ENABLE_GUESTS=0 : sans jeton signe, aucune salle ne s'ouvre.
        // `app_id` et `audience` doivent correspondre EXACTEMENT a JWT_APP_ID
        // et JWT_ACCEPTED_AUDIENCES cote prosody, sinon les jetons sont rejetes
        // sans le moindre message exploitable.
        'jitsi' => [
            'app_id' => env('JITSI_APP_ID', 'lms-klassci'),
            'app_secret' => env('JITSI_APP_SECRET'),
            'audience' => env('JITSI_AUDIENCE', 'visio-klassci'),
            'domain' => env('JITSI_DOMAIN'),
            // XMPP_DOMAIN cote serveur : domaine INTERNE, jamais resolu par
            // le navigateur. A laisser au defaut.
            'xmpp_domain' => env('JITSI_XMPP_DOMAIN', 'meet.jitsi'),
            'token_lifetime' => (int) env('JITSI_TOKEN_LIFETIME', 7200),
        ],
    ],

];
