/**
 * lib/http.js — Wrapper HTTP partagé pour tous les scénarios k6 (#372).
 *
 * ## Pourquoi
 *
 * Chaque scénario k6 (`tests/load/scenarios/*.js`) a besoin d'émettre des
 * requêtes HTTP vers l'API Laravel selon exactement deux patrons d'authentification
 * possibles côté serveur (`app/Http/Middleware/ResolveInstitution.php:15-34`) :
 *
 * 1. **Requête authentifiée** (séances, notifications, dashboard, proxy Klassci) :
 *    un Bearer token Sanctum est présent. Le middleware résout le tenant
 *    EXCLUSIVEMENT depuis `token.tokenable.institution_id` — tout header
 *    `X-Institution` envoyé en plus serait silencieusement ignoré
 *    (`ResolveInstitution.php:17-21`).
 * 2. **Requête pré-authentification** (login uniquement) : aucun Bearer token
 *    n'existe encore, le tenant est résolu depuis le header `X-Institution`
 *    (`ResolveInstitution.php:23-26`).
 *
 * Centraliser ces deux constructions ici (plutôt que de les dupliquer dans
 * chaque fichier de scénario) respecte la convention de réutilisation du
 * harnais (Requirement 1.2 — voir `design.md` §Components/2) : aucun scénario
 * ne doit redéfinir sa propre logique de header d'authentification.
 *
 * @see .claude/specs/372-k6-load-testing/design.md §Components and Interfaces/2
 */

import http from 'k6/http';
import { BASE_URL } from './config.js';

/**
 * Exécute une requête GET authentifiée par un token Sanctum "Bearer".
 *
 * Utilisée par tous les scénarios de lecture authentifiée : liste des
 * séances (Requirement 3.1), proxy Klassci (Requirement 4), notifications
 * et dashboard/stats (Requirement 5.1, 5.2). Chaque appelant doit fournir
 * une entrée `users[]` distincte de la fixture par VU (D6 du design — un
 * pool d'utilisateurs partagé plus petit que le nombre de VUs fausserait
 * la mesure de débit, en particulier pour le throttle `proxy` qui est
 * appliqué par utilisateur).
 *
 * @param {string} path - Chemin relatif à `BASE_URL` (ex. `/api/lms/seances/upcoming`).
 * @param {{ token: string, token_type?: string }} user - Entrée `users[]` de
 *   `setup/fixtures/load-test-users.json` (voir `design.md` §Data Models).
 * @param {object} [params] - Paramètres k6 additionnels (`tags`, `timeout`, `headers`...),
 *   fusionnés par-dessus les défauts ; `params.headers` complète (et peut surcharger)
 *   les en-têtes par défaut sans jamais les remplacer intégralement.
 * @returns {import('k6/http').RefinedResponse<any>} La réponse k6 brute, à passer
 *   à `lib/checks.js#expectEnvelopeSuccess()` par l'appelant.
 */
export function authenticatedGet(path, user, params = {}) {
    const { headers: extraHeaders, ...restParams } = params;

    return http.get(`${BASE_URL}${path}`, {
        ...restParams,
        headers: {
            Authorization: `${user.token_type || 'Bearer'} ${user.token}`,
            Accept: 'application/json',
            ...extraHeaders,
        },
    });
}

/**
 * Exécute une requête POST non authentifiée, résolvant le tenant via le
 * header `X-Institution` (seul chemin de résolution valide en l'absence de
 * Bearer token — priorité 2 de `ResolveInstitution`).
 *
 * Réservée au scénario login (Requirement 2.1) : c'est le seul appel du
 * harnais qui se produit avant l'émission d'un token, donc le seul endroit
 * légitime pour ce header. `institutionSlug` doit systématiquement provenir
 * de `fixtures.institution.slug` (tenant de test isolé, Requirement 6.1) —
 * jamais d'un slug d'institution réelle codé en dur (Requirement 12.2).
 *
 * @param {string} path - Chemin relatif à `BASE_URL` (ex. `/api/auth/login`).
 * @param {object} body - Corps de la requête, sérialisé en JSON.
 * @param {string} institutionSlug - Slug du tenant de test (`fixtures.institution.slug`).
 * @param {object} [params] - Paramètres k6 additionnels, fusionnés par-dessus les défauts
 *   selon la même règle que `authenticatedGet`.
 * @returns {import('k6/http').RefinedResponse<any>} La réponse k6 brute.
 */
export function publicPost(path, body, institutionSlug, params = {}) {
    const { headers: extraHeaders, ...restParams } = params;

    return http.post(`${BASE_URL}${path}`, JSON.stringify(body), {
        ...restParams,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Institution': institutionSlug,
            ...extraHeaders,
        },
    });
}
