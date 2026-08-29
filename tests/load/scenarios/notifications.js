/**
 * scenarios/notifications.js — Scénario de charge « notifications » (#372, Requirement 5.1).
 *
 * ## Endpoint ciblé
 *
 * `GET /api/notifications` (`routes/api/admin.php:55-57`, `Route::get('/', ...)` sous le
 * groupe `Route::middleware(['auth:sanctum'])->prefix('notifications')`), résolu vers
 * `NotificationsController::index`. Middleware `auth:sanctum` UNIQUEMENT — à la différence
 * des séances (§3.2), du proxy Klassci (§3.3) et du dashboard (§3.5), cette route ne traverse
 * PAS `klassci.sync` : c'est une lecture DB pure, sans dépendance à `EnsureKlassciSync` ni à
 * `User::isKlassciDataFresh()`, et sans throttle dédié.
 *
 * Ce scénario sert donc de RÉFÉRENCE DE COMPARAISON DE LATENCE PLANCHER pour le harnais :
 * toute dégradation mesurée ici ne peut être imputée ni au throttle `proxy` (100/min/user),
 * ni à un appel externe simulé par `EnsureKlassciSync` sur les autres scénarios — seulement à
 * l'application Laravel et à la base de données (MySQL/SQLite) elles-mêmes. Comparer
 * `http_req_duration{scenario:notifications}` à `http_req_duration{scenario:seances}` ou
 * `{scenario:dashboard}` permet d'isoler le coût du middleware `klassci.sync` dans les
 * mesures de charge (design.md §Components/3.4).
 *
 * ## Patron d'implémentation
 *
 * Identique à `scenarios/seances-list.js` (Requirement 3, même patron d'authentification et
 * de vérification pour les endpoints de lecture authentifiée du harnais) : 1 utilisateur
 * distinct par VU, checks d'enveloppe JSON standard, seuils scopés par tag
 * `scenario:notifications`. Voir design.md §3.4.
 *
 * ## Cohérence stricte des 3 tags `scenario:notifications`
 *
 * Le tag `scenario: 'notifications'` est posé IDENTIQUE à trois endroits (contrat documenté
 * dans `lib/thresholds.js`) :
 *   1. sur la requête HTTP elle-même (`authenticatedGet(path, user, { tags: { scenario: 'notifications' } })`) ;
 *   2. sur le check d'enveloppe (`expectEnvelopeSuccess(res, 200, { scenario: 'notifications' })`) ;
 *   3. sur le `scenarioTag` passé à `standardThresholds('notifications')`.
 * Sans cette cohérence, les seuils `http_req_duration{scenario:notifications}` et
 * `checks{scenario:notifications}` resteraient sans aucune série de métrique mesurée, et le
 * seuil serait silencieusement ignoré par k6 plutôt que de refléter le comportement réel de
 * l'endpoint.
 *
 * @see .claude/specs/372-k6-load-testing/design.md §3.4, §Components and Interfaces/2 (D6)
 */
import { authenticatedGet } from '../lib/http.js';
import { expectEnvelopeSuccess } from '../lib/checks.js';
import { standardThresholds } from '../lib/thresholds.js';
import { writeScenarioSummary } from '../lib/summary.js';
import { loadFixtures } from '../lib/fixtures.js';

// Chargement de la fixture en contexte d'init k6 (une fois par VU, avant le début des
// itérations) — jamais dans `export default function()` (voir `lib/fixtures.js`, contrainte
// d'usage de `open()`).
const fixtures = loadFixtures();

// Tag de scénario unique, réutilisé identique pour la requête HTTP, le check d'enveloppe et
// les seuils (voir commentaire "Cohérence stricte des 3 tags" ci-dessus).
const SCENARIO_TAG = 'notifications';

export const options = {
    scenarios: {
        notifications_list: {
            executor: 'constant-vus',
            vus: Number(__ENV.NOTIF_VUS) || 20,
            duration: __ENV.NOTIF_DURATION || '2m',
        },
    },
    thresholds: {
        ...standardThresholds(SCENARIO_TAG),
    },
};

/**
 * Exécute une requête `GET /api/notifications` authentifiée pour `user` et vérifie
 * l'enveloppe de réponse. Exportée (pas seulement appelée depuis `export default` ci-dessous)
 * pour que `scenarios/ramp-up.js` puisse la réimporter telle quelle dans son mix d'endpoints
 * (Requirement 1.2 — jamais de logique de requête dupliquée entre ce fichier et le ramp-up).
 *
 * @param {{ token: string, token_type?: string }} user - Entrée `fixtures.users[]`.
 * @returns {import('k6/http').RefinedResponse<any>} La réponse k6 brute.
 */
export function requestNotifications(user) {
    const res = authenticatedGet('/api/notifications', user, {
        tags: { scenario: SCENARIO_TAG },
    });

    expectEnvelopeSuccess(res, 200, { scenario: SCENARIO_TAG });

    return res;
}

/**
 * Itération k6 : une requête `GET /api/notifications` authentifiée par le token de
 * l'utilisateur assigné à ce VU.
 */
export default function () {
    // `__VU` démarre à 1 (convention k6) ; le modulo garantit un index valide même si
    // `NOTIF_VUS` dépasse `fixtures.users.length` par erreur de configuration (même patron
    // que `scenarios/seances-list.js`, recommandation : NOTIF_VUS <= fixtures.users.length).
    const user = fixtures.users[(__VU - 1) % fixtures.users.length];

    requestNotifications(user);
}

/**
 * Délègue l'écriture du résumé de fin de tir à `lib/summary.js` — jamais réimplémenté
 * localement (Requirement 1.2, convention de réutilisation du harnais).
 */
export function handleSummary(data) {
    return writeScenarioSummary(data, 'notifications');
}
