/**
 * scenarios/dashboard-stats.js — Scénario de charge « dashboard » (#372, Requirement 5.2).
 *
 * ## Endpoint ciblé — décision explicite `/dashboard/student` vs `/dashboard/stats`
 *
 * `GET /api/dashboard/student` (`routes/api/lms.php:19-21`), sous le groupe de middleware
 * `auth:sanctum`, `klassci.sync` (`routes/api/lms.php:19`), accessible à TOUS les
 * utilisateurs authentifiés (aucun middleware `role:*` sur cette route précise).
 *
 * `GET /api/dashboard/stats` (`routes/api/lms.php:28-29`) est ÉCARTÉ au profit de
 * `/dashboard/student`, car `stats` est restreint à `role:coordinateur,admin`
 * (`routes/api/lms.php:29`) — incompatible avec la population d'utilisateurs de test
 * générique de ce harnais : `setup/prepare-load-test-data.php` fixe `role='etudiant'`
 * pour tous les utilisateurs générés (D6 du design, `design.md` §4.2 — un rôle unique
 * pour éliminer une variable confondante entre tirs successifs et garantir des
 * utilisateurs interchangeables). Router N utilisateurs `etudiant` vers `/dashboard/stats`
 * produirait 100 % de réponses 403, ce qui ne mesurerait rien d'utile. `/dashboard/student`
 * est donc la seule route « dashboard » ouverte à tous les rôles, et la seule représentative
 * du volume réel de trafic visé par le Requirement 5 avec cette population de test.
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
 * `__VU` démarre à 1 (convention k6), d'où le `-1` pour obtenir un index base-0. Cette route n'a
 * pas de throttle par utilisateur (contrairement au proxy Klassci, 100/min/user), donc réutiliser
 * un utilisateur pour plusieurs VUs ne fausserait pas ici la mesure de débit par un rate-limit
 * prématuré. Le modulo est néanmoins conservé par cohérence avec le patron D6 commun à tous les
 * scénarios authentifiés du harnais (`lib/http.js#authenticatedGet`, design.md §3.4.2) et pour
 * éviter un crash (accès hors bornes) si `DASH_VUS` dépassait accidentellement
 * `fixtures.users.length`. Documenté explicitement : `DASH_VUS` DEVRAIT rester ≤
 * `fixtures.users.length` pour que chaque VU dispose d'un utilisateur réellement unique tout au
 * long du tir (même recommandation opérationnelle que `scenarios/seances-list.js`).
 *
 * ## Cohérence stricte des 3 tags `scenario:dashboard`
 *
 * Le tag `scenario: 'dashboard'` est posé IDENTIQUE à trois endroits (contrat documenté dans
 * `lib/thresholds.js`) :
 *   1. sur la requête HTTP elle-même (`authenticatedGet(path, user, { tags: { scenario: 'dashboard' } })`) ;
 *   2. sur le check d'enveloppe (`expectEnvelopeSuccess(res, 200, { scenario: 'dashboard' })`) ;
 *   3. sur le `scenarioTag` passé à `standardThresholds('dashboard')`.
 * Sans cette cohérence, les seuils `http_req_duration{scenario:dashboard}` et
 * `checks{scenario:dashboard}` resteraient sans aucune série de métrique mesurée (aucune requête
 * n'y contribuerait), et le seuil serait silencieusement ignoré par k6 plutôt que de refléter le
 * comportement réel de l'endpoint.
 *
 * @see .claude/specs/372-k6-load-testing/design.md §3.5, §Components and Interfaces/2 (D6)
 */
import { sleep } from 'k6';
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
const SCENARIO_TAG = 'dashboard';

export const options = {
    scenarios: {
        dashboard_stats: {
            executor: 'constant-vus',
            vus: Number(__ENV.DASH_VUS) || 20,
            duration: __ENV.DASH_DURATION || '2m',
        },
    },
    thresholds: {
        ...standardThresholds(SCENARIO_TAG),
    },
};

/**
 * Exécute une requête `GET /api/dashboard/student` authentifiée pour `user` et vérifie
 * l'enveloppe de réponse. Exportée (pas seulement appelée depuis `export default` ci-dessous)
 * pour que `scenarios/ramp-up.js` puisse la réimporter telle quelle dans son mix d'endpoints
 * (Requirement 1.2 — jamais de logique de requête dupliquée entre ce fichier et le ramp-up).
 *
 * Ne contient volontairement PAS le `sleep(1)` de pacing ci-dessous : ce temps de pause est
 * une caractéristique de CE scénario isolé, pas de la requête elle-même — le ramp-up gère son
 * propre rythme d'itération, indépendant de celui des scénarios unitaires qu'il réimporte.
 *
 * @param {{ token: string, token_type?: string }} user - Entrée `fixtures.users[]`.
 * @returns {import('k6/http').RefinedResponse<any>} La réponse k6 brute.
 */
export function requestDashboard(user) {
    const res = authenticatedGet('/api/dashboard/student', user, {
        tags: { scenario: SCENARIO_TAG },
    });

    expectEnvelopeSuccess(res, 200, { scenario: SCENARIO_TAG });

    return res;
}

/**
 * Itération k6 : une requête `GET /api/dashboard/student` authentifiée par le token de
 * l'utilisateur assigné à ce VU.
 */
export default function () {
    // `__VU` démarre à 1 (convention k6) ; le modulo garantit un index valide même si
    // `DASH_VUS` dépasse `fixtures.users.length` par erreur de configuration (voir
    // commentaire de tête sur la recommandation DASH_VUS <= fixtures.users.length).
    const user = fixtures.users[(__VU - 1) % fixtures.users.length];

    requestDashboard(user);

    sleep(1);
}

/**
 * Délègue l'écriture du résumé de fin de tir à `lib/summary.js` — jamais réimplémenté
 * localement (Requirement 1.2, convention de réutilisation du harnais).
 */
export function handleSummary(data) {
    return writeScenarioSummary(data, 'dashboard-stats');
}
