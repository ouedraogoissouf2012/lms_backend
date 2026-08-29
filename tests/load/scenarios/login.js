/**
 * scenarios/login.js — Scénario de charge k6 pour `POST /api/auth/login` (#372, Requirement 2).
 *
 * ## Pourquoi ce scénario est différent de tous les autres
 *
 * `POST /api/auth/login` (`routes/api/core.php:74`) est protégé par
 * `throttle:10,1` — 10 tentatives par MINUTE, par ADRESSE IP (limiteur Laravel
 * standard, pas de clé composite utilisateur). Tous les VUs k6 lancés depuis
 * la même machine partagent donc la MÊME IP source, donc le MÊME quota,
 * quel que soit le nombre de VUs configuré (`design.md` §"Business Process",
 * Processus 3).
 *
 * C'est un choix de sécurité assumé du projet (cf. commentaire de la route :
 * "aligned with Auth0 / Cloudflare defaults... reduces brute-force throughput
 * by 83%") — PAS un bug à corriger, et CE SCÉNARIO NE DOIT JAMAIS chercher à
 * le contourner. En particulier : ne jamais suggérer de modifier
 * `app/Providers/RateLimitServiceProvider.php` ni le throttle de la route
 * login pour faire "passer" un débit multi-utilisateur artificiel — un vrai
 * test de débit multi-utilisateur du login nécessiterait une infrastructure
 * multi-IP hors périmètre de ce harnais.
 *
 * Conséquence directe sur la conception de ce fichier :
 *   - `LOGIN_VUS`/`LOGIN_DURATION` par défaut sont VOLONTAIREMENT petits
 *     (5 VUs, 1 minute) : au-delà d'environ 10 requêtes/minute cumulées tous
 *     VUs confondus, la majorité des requêtes recevront un 429 par
 *     construction, indépendamment du nombre de VUs configuré.
 *   - Un statut 429 est un résultat GÉRÉ et ATTENDU, jamais un échec de test.
 *   - Le seuil de qualité ne porte que sur les statuts réellement anormaux
 *     (5xx, 4xx hors 429, ou 200 sans token exploitable) — voir
 *     `login_unexpected_error` ci-dessous.
 *
 * ## Authentification
 *
 * Contrairement aux scénarios séances/notifications/dashboard/proxy, ce
 * scénario n'a PAS encore de Bearer token (c'est justement l'endpoint qui en
 * émet un) : le tenant est donc résolu via le header `X-Institution`
 * (`lib/http.js#publicPost`), avec le slug du tenant de test isolé
 * (`fixtures.institution.slug`, Requirement 6.1) — jamais un slug
 * d'institution réelle codé en dur (Requirement 12.2).
 *
 * @see .claude/specs/372-k6-load-testing/design.md §3.1, §"Business Process" Processus 3
 */
import { check } from 'k6';
import { Counter, Rate } from 'k6/metrics';
import { loadFixtures } from '../lib/fixtures.js';
import { publicPost } from '../lib/http.js';
import { writeScenarioSummary } from '../lib/summary.js';

/**
 * Tag k6 posé sur chaque requête HTTP et chaque `check()` de ce scénario, afin
 * d'isoler ses propres métriques dans le résumé de fin de tir (même
 * convention de tagging que les autres scénarios via `lib/thresholds.js`,
 * bien que ce fichier n'utilise pas `standardThresholds()` — voir plus bas).
 *
 * @type {string}
 */
const SCENARIO_TAG = 'login';

/**
 * Chargée UNE FOIS au niveau module (contexte d'init k6, avant le début des
 * itérations) — `open()` n'est utilisable qu'à ce moment-là (voir
 * `lib/fixtures.js`). Fournit `fixtures.institution.slug` (tenant de test
 * isolé) et `fixtures.login_pool` (identifiants en clair générés par
 * `setup/prepare-load-test-data.php`, jamais commités — Requirement 12.1).
 *
 * @type {object}
 */
const fixtures = loadFixtures();

/**
 * Nombre de tentatives ayant reçu un 429 (rate-limit IP) — un COMPTEUR
 * d'information, PAS un signal d'échec : ce scénario est justement conçu
 * pour heurter ce quota (voir en-tête de fichier). Utile pour confirmer, en
 * lisant le résumé, que le throttle a bien été exercé pendant le tir.
 */
export const loginRateLimitedTotal = new Counter('login_rate_limited_total');

/**
 * Taux d'échec RÉEL du login — n'inclut JAMAIS le 429 (voir en-tête de
 * fichier). Incrémentée uniquement quand :
 *   - le statut HTTP n'est ni 200 ni 429 (5xx, 401, 422 hors throttle...) ;
 *   - le statut est 200 mais `data.token` est absent/vide dans le corps ;
 *   - le statut est 200 mais `success` n'est pas strictement `true`.
 *
 * Seule métrique adossée à un seuil dans ce fichier (Requirement 2.3) :
 * `login_unexpected_error: ['rate<0.01']`. Volontairement PAS de seuil
 * `http_req_failed` global, qui confondrait un 429 attendu avec une vraie
 * panne du service.
 */
export const loginUnexpectedError = new Rate('login_unexpected_error');

/**
 * Options k6 de ce scénario.
 *
 * Exécuteur `constant-vus` avec des défauts volontairement faibles
 * (`LOGIN_VUS=5`, `LOGIN_DURATION=1m`) — voir en-tête de fichier pour la
 * justification complète : au-delà d'~10 req/min cumulées, ce scénario ne
 * mesure plus rien d'utile, il ne fait que confirmer que le rate limiter
 * fonctionne.
 */
export const options = {
    scenarios: {
        login: {
            executor: 'constant-vus',
            vus: Number(__ENV.LOGIN_VUS) || 5,
            duration: __ENV.LOGIN_DURATION || '1m',
        },
    },
    thresholds: {
        // Requirement 2.3 : le SEUL seuil de ce scénario. Pas de seuil
        // `http_req_failed`/`checks` générique ici (voir en-tête de fichier).
        login_unexpected_error: ['rate<0.01'],
    },
};

/**
 * Sélectionne une entrée du `login_pool` de façon cyclique, répartie entre
 * VUs ET entre itérations d'un même VU — le pool est volontairement petit
 * (5 entrées max, `LOGIN_POOL_SIZE` de `prepare-load-test-data.php`), le
 * faire tourner évite que tous les VUs martèlent systématiquement la même
 * entrée en premier.
 *
 * @param {Array<{username: string, password: string}>} pool
 * @returns {{username: string, password: string}}
 */
function pickLoginCredentials(pool) {
    const index = (__VU - 1 + __ITER) % pool.length;
    return pool[index];
}

export default function () {
    const credentials = pickLoginCredentials(fixtures.login_pool);

    const res = publicPost(
        '/api/auth/login',
        { username: credentials.username, password: credentials.password },
        fixtures.institution.slug,
        { tags: { scenario: SCENARIO_TAG } }
    );

    if (res.status === 429) {
        loginRateLimitedTotal.add(1, { scenario: SCENARIO_TAG });
    }

    let body = null;
    let isValidJson = true;
    try {
        body = res.json();
    } catch (error) {
        isValidJson = false;
    }
    const hasBody = isValidJson && body !== null && typeof body === 'object';

    // Statut "géré" : 200 (login réussi) ou 429 (throttle IP attendu, voir
    // en-tête de fichier). Tout le reste est un échec réel.
    const isHandledStatus = res.status === 200 || res.status === 429;

    // Les deux checks suivants ne s'appliquent QUE sur un 200 — un 429 les
    // court-circuite à `true` par construction (`res.status !== 200`),
    // conformément à la règle : "échec UNIQUEMENT si ... 200 sans data.token
    // ... OU success !== true sur un 200" (jamais sur un 429).
    const hasTokenOn200 =
        res.status !== 200 ||
        (hasBody &&
            typeof body.data === 'object' &&
            body.data !== null &&
            typeof body.data.token === 'string' &&
            body.data.token.trim() !== '');

    const hasSuccessTrueOn200 = res.status !== 200 || (hasBody && body.success === true);

    const passed = check(
        res,
        {
            'statut géré (200 ou 429 — throttle IP attendu)': () => isHandledStatus,
            'si 200 : data.token présent et non vide': () => hasTokenOn200,
            'si 200 : success strictement true': () => hasSuccessTrueOn200,
        },
        { scenario: SCENARIO_TAG }
    );

    loginUnexpectedError.add(!passed, { scenario: SCENARIO_TAG });
}

/**
 * Délègue au résumé partagé du harnais (Requirement 1.2) — écrit
 * `tests/load/results/login__<timestamp>[__<run-label>].json` en plus du
 * résumé texte standard sur stdout.
 */
export function handleSummary(data) {
    return writeScenarioSummary(data, 'login');
}
