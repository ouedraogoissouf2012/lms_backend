# Tasks — #545 [P2][SECURITY] CORS wildcard + SESSION_SECURE_COOKIE sans défaut

- [x] 1. Test RED : Feature test CORS (`tests/Feature/Security/CorsAllowedOriginsTest.php`)
  - Requête avec `Origin: https://edu.klassci.com` sur une route `api/*` existante
    → `Access-Control-Allow-Origin` = `https://edu.klassci.com`
  - Requête avec `Origin: https://attaquant.example` → header absent OU différent
    de `https://attaquant.example` (jamais l'origine attaquant reflétée)
  - Doit échouer AVANT création de `config/cors.php` (défaut vendor `*` en vigueur)
  - _Requirements: R1, R2_

- [x] 2. GREEN : créer `config/cors.php`
  - `allowed_origins` via `CORS_ALLOWED_ORIGINS` (défaut `https://edu.klassci.com`,
    `trim` + `array_filter` sur la liste séparée par virgules)
  - Autres clés identiques au défaut vendor
  - _Requirements: R1, R2_

- [x] 3. Test RED : `tests/Unit/Config/SessionSecureCookieDefaultTest.php`
  - `APP_ENV=production` + `SESSION_SECURE_COOKIE` absent → `secure === true`
  - `APP_ENV=local` + `SESSION_SECURE_COOKIE` absent → `secure === false`
    (documente le comportement actuel déjà correct — non-régression)
  - `APP_ENV=production` + `SESSION_SECURE_COOKIE=false` explicite → `secure === false`
    (l'override explicite reste respecté)
  - Premier cas doit échouer AVANT le fix (`secure` vaut `null` aujourd'hui)
  - _Requirements: R3, R4, R5_

- [x] 4. GREEN : `config/session.php:177`
  - `'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV', 'production') === 'production')`
  - _Requirements: R3, R4, R5_

- [x] 5. Audits `spec-security` + `spec-architect` en parallèle (CONTRIBUTING.md §A)
  - Sécurité : PASS, 0 finding — vérifié `CorsService` (comportement réel confirmé,
    fail-closed sur env malformée), APP_ENV=production réel en prod
    (docs/DEPLOIEMENT_CPANEL.md:59), phpunit.xml=testing inchangé
  - Architecture : PASS, 0 finding bloquant — 1 note MEDIUM (design.md pas à jour
    avec le cast `(string)` ajouté après coup pour PHPStan) corrigée

- [x] 6. `php artisan test` (suite impactée) + PHPStan level 9 sur les 2 fichiers touchés
  - 359 passed, 1 skipped (Redis indisponible localement — attendu, jambe CI dédiée)
  - PHPStan : 0 erreur après ajout du cast `(string)` sur `env(...)` dans cors.php

- [ ] 7. PR vers `lms`
