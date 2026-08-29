/**
 * scenarios/seances-list.js — Scénario de charge « liste des séances » (#372, Requirement 3).
 *
 * ## Endpoint ciblé
 *
 * `GET /api/lms/seances/upcoming` (`routes/api/lms.php:91-92`), sous le groupe de
 * middleware `auth:sanctum`, `klassci.sync` (`routes/api/lms.php:84`) — aucun throttle
 * dédié sur cette route, contrairement au proxy Klassci (`throttle:proxy`) ou au login
 * (`throttle:10,1`). C'est un parcours de lecture fréquent et représentatif de la charge
 * multi-tenant réelle (design.md §3.2).
 *
 * `EnsureKlassciSync` (déclenché par `klassci.sync`) ne provoque AUCUN appel HTTP externe
 * pendant ce scénario : la fixture générée par `setup/prepare-load-test-data.php` crée
 * chaque utilisateur avec `last_klassci_sync = now()` (fraîcheur < 24h, `User::isKlassciDataFresh()`),
 * donc `assertKlassciStubIsLoopback()` de `lib/fixtures.js` n'est PAS appelée ici — seul le
 * scénario `proxy-klassci-read.js` touche réellement le stub Klassci (design.md §Architecture,
 * "Point clé").
 *
 * ## 1 utilisateur distinct par VU (D6 du design)
 *
 * Chaque VU utilise sa propre entrée `fixtures.users[]`, indexée par `(__VU - 1) % users.length`.
 * `__VU` démarre à 1 (convention k6), d'où le `-1` pour obtenir un index base-0.
 *
 * Cette route n'a pas de throttle par utilisateur (contrairement au proxy Klassci, 100/min/user),
 * donc réutiliser un utilisateur pour plusieurs VUs ne fausserait pas ici la mesure de débit par
 * un rate-limit prématuré. Le modulo est néanmoins conservé par cohérence avec le patron D6 commun
 * à tous les scénarios authentifiés du harnais (`lib/http.js#authenticatedGet`, design.md §3.4.2) et
 * pour éviter un crash (`undefined` d'accès hors bornes) si `SEANCES_VUS` dépassait accidentellement
 * `fixtures.users.length`. Documenté explicitement : `SEANCES_VUS` DEVRAIT rester ≤
 * `fixtures.users.length` pour que chaque VU dispose d'un utilisateur réellement unique tout au long
 * du tir (recommandation opérationnelle, pas une contrainte structurelle stricte comme pour le proxy).
 *
 * ## Cohérence stricte des 3 tags `scenario:seances`
 *
 * Le tag `scenario: 'seances'` est posé IDENTIQUE à trois endroits (contrat documenté dans
 * `lib/thresholds.js`) :
 *   1. sur la requête HTTP elle-même (`authenticatedGet(path, user, { tags: { scenario: 'seances' } })`) ;
 *   2. sur le check d'enveloppe (`expectEnvelopeSuccess(res, 200, { scenario: 'seances' })`) ;
 *   3. sur le `scenarioTag` passé à `standardThresholds('seances')`.
 * Sans cette cohérence, les seuils `http_req_duration{scenario:seances}` et
 * `checks{scenario:seances}` resteraient sans aucune série de métrique mesurée (aucune requête
 * n'y contribuerait), et le seuil serait silencieusement ignoré par k6 plutôt que de refléter le
 * comportement réel de l'endpoint.
 *
 * @see .claude/specs/372-k6-load-testing/design.md §3.2, §Components and Interfaces/2 (D6)
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
const SCENARIO_TAG = 'seances';

export const options = {
    scenarios: {
        seances_list: {
            executor: 'constant-vus',
            vus: Number(__ENV.SEANCES_VUS) || 20,
            duration: __ENV.SEANCES_DURATION || '2m',
        },
    },
    thresholds: {
        ...standardThresholds(SCENARIO_TAG),
    },
};

/**
 * Exécute une requête `GET /api/lms/seances/upcoming` authentifiée pour `user` et vérifie
 * l'enveloppe de réponse. Exportée (pas seulement appelée depuis `export default` ci-dessous)
 * pour que `scenarios/ramp-up.js` puisse la réimporter telle quelle dans son mix d'endpoints
 * (Requirement 1.2 — jamais de logique de requête dupliquée entre ce fichier et le ramp-up).
 *
 * @param {{ token: string, token_type?: string }} user - Entrée `fixtures.users[]`.
 * @returns {import('k6/http').RefinedResponse<any>} La réponse k6 brute.
 */
export function requestSeances(user) {
    const res = authenticatedGet('/api/lms/seances/upcoming', user, {
        tags: { scenario: SCENARIO_TAG },
    });

    expectEnvelopeSuccess(res, 200, { scenario: SCENARIO_TAG });

    return res;
}

/**
 * Itération k6 : une requête `GET /api/lms/seances/upcoming` authentifiée par le token de
 * l'utilisateur assigné à ce VU.
 */
export default function () {
    // `__VU` démarre à 1 (convention k6) ; le modulo garantit un index valide même si
    // `SEANCES_VUS` dépasse `fixtures.users.length` par erreur de configuration (voir
    // commentaire de tête sur la recommandation SEANCES_VUS <= fixtures.users.length).
    const user = fixtures.users[(__VU - 1) % fixtures.users.length];

    requestSeances(user);
}

/**
 * Délègue l'écriture du résumé de fin de tir à `lib/summary.js` — jamais réimplémenté
 * localement (Requirement 1.2, convention de réutilisation du harnais).
 */
export function handleSummary(data) {
    return writeScenarioSummary(data, 'seances-list');
}
