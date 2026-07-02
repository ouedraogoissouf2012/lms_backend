<?php

/*
|--------------------------------------------------------------------------
| API Routes — LMS KLASSCI Backend
|--------------------------------------------------------------------------
| Découpé par domaine dans routes/api/*.php (§1.1 ≤300 lignes). Ce fichier est
| chargé DEUX fois par bootstrap/app.php (/api et /api/v1, versioning #217) —
| d'où `require` (PAS require_once) : les deux montages doivent réenregistrer
| les routes.
*/

require __DIR__ . '/api/core.php';
require __DIR__ . '/api/content.php';
require __DIR__ . '/api/lms.php';
require __DIR__ . '/api/evaluation.php';
require __DIR__ . '/api/admin.php';
