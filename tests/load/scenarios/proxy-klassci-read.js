/**
 * scenarios/proxy-klassci-read.js — Scénario de charge proxy KLASSCI en lecture (#372, Requirement 4).
 *
 * ## Pourquoi
 *
 * `KlassciProxyService` (`app/Services/Klassci/`) proxifie 8 endpoints de lecture
 * organisationnelle/académique vers l'API KLASSCI (`routes/api/core.php:101-123`,
 * groupe `proxy` sous `auth:sanctum`, `klassci.sync`, `throttle:proxy`). Ce scénario
 * mesure la latence ajoutée par cette proxification sous charge, SANS jamais
 * solliciter l'API KLASSCI réelle — voir la garde D4 ci-dessous.
 *
 * ## Endpoint ciblé
 *
 * `GET /api/proxy/{PROXY_ENDPOINT}`, `PROXY_ENDPOINT` résolu depuis `__ENV.PROXY_ENDPOINT`
 * (défaut `'structure'`). Valeurs valides connues, toutes servies par
 * `stub/klassci-stub-server.php` (design.md §5.2) : `structure`, `classes`, `filieres`,
 * `niveaux-etudes`, `matieres`, `enseignants`, `emploi-temps`, `evaluations`. Le groupe de
 * routes traversé ici n'exige AUCUN rôle particulier (`routes/api/core.php:101-123`, à ne
 * pas confondre avec le groupe `role:coordinateur,superAdmin,supradmin` des lignes 90-95) —
 * cohérent avec des utilisateurs de test génériques `role='etudiant'` (D6 du design).
 *
 * ## Throttle `proxy` — 100 req/min PAR UTILISATEUR (pas par IP)
 *
 * `app/Providers/RateLimitServiceProvider.php:47,52-54` : le throttle `proxy` est
 * scopé par utilisateur authentifié, contrairement au throttle IP du login
 * (`scenarios/login.js`, `routes/api/core.php:74`). Avec N utilisateurs de test
 * DISTINCTS (1 par VU, cf. §D6 ci-dessous), le débit plafond théorique croît
 * linéairement avec le nombre de VUs (≈ N × 1,67 req/s) — ce scénario scale donc
 * correctement, contrairement au login qui plafonne à un quota IP fixe quel que
 * soit le nombre de VUs.
 *
 * ## Garde de lancement CRITIQUE — D4 (Requirement 4.2)
 *
 * `assertKlassciStubIsLoopback(fixtures)` est appelée ICI, explicitement, juste après
 * `loadFixtures()`, au niveau module (contexte d'init k6, avant tout VU). C'est cet
 * appel — et lui seul — qui déclenche la garde : `lib/fixtures.js` ne l'exécute
 * JAMAIS automatiquement. Si `fixtures.klassci_stub_url` ne pointe pas vers un host
 * loopback (`127.0.0.1`/`localhost`/`::1`), `exec.test.abort()` interrompt
 * l'intégralité du tir AVANT qu'aucune requête HTTP ne parte — empêchement
 * structurel, pas une simple alerte. C'est le SEUL scénario du harnais qui appelle
 * effectivement `KlassciProxyService` de façon inconditionnelle (design.md §Architecture,
 * "Point clé") : séances/notifications/dashboard ne touchent jamais le stub car la
 * fixture est générée avec `last_klassci_sync = now()`.
 *
 * ## 1 utilisateur distinct par VU (D6)
 *
 * Même patron que `scenarios/seances-list.js` (design.md §3.2) : indexation modulo
 * `users[(__VU - 1) % users.length]`. `PROXY_VUS` doit idéalement rester
 * ≤ `fixtures.users.length` — au-delà, plusieurs VUs partageraient le même
 * utilisateur (même token), et donc le même compteur de throttle `proxy`,
 * plafonnant artificiellement le débit mesuré indépendamment du nombre de VUs
 * réellement configuré (voir `lib/http.js#authenticatedGet` et design.md D6).
 *
 * @see .claude/specs/372-k6-load-testing/design.md §3.3, D4, D6
 * @see .claude/specs/372-k6-load-testing/requirements.md Requirement 4
 */

import { authenticatedGet } from '../lib/http.js';
import { loadFixtures, assertKlassciStubIsLoopback } from '../lib/fixtures.js';
import { expectEnvelopeSuccess } from '../lib/checks.js';
import { standardThresholds } from '../lib/thresholds.js';
import { writeScenarioSummary } from '../lib/summary.js';

/** Tag k6 identifiant ce scénario — DOIT rester identique partout où il apparaît
 * (requête HTTP, check, seuils) : c'est ce qui permet à `standardThresholds()` de
 * scoper ses métriques sans les agréger avec les autres scénarios (Requirement 4.4). */
const SCENARIO_TAG = 'proxy-klassci';

/**
 * Chargement de la fixture en contexte d'init k6 (une fois par VU, avant le début
 * des itérations) — voir `lib/fixtures.js` pour la justification de ce placement.
 */
const fixtures = loadFixtures();

/**
 * Garde D4 (Requirement 4.2) — voir en-tête du fichier. Appelée explicitement ICI,
 * immédiatement après `loadFixtures()` : sans cet appel, la garde ne s'exécute
 * JAMAIS et un `klassci_stub_url` non-loopback (ex. domaine KLASSCI réel) ne serait
 * jamais détecté avant l'envoi de requêtes.
 */
assertKlassciStubIsLoopback(fixtures);

/**
 * Endpoint KLASSCI proxifié ciblé par ce tir, résolu depuis `__ENV.PROXY_ENDPOINT`.
 * Défaut `'structure'` — endpoint le plus représentatif, agrégeant filières et
 * niveaux d'études en une seule réponse (`ProxyOrganisationController::structure()`).
 *
 * @type {string}
 */
const PROXY_ENDPOINT = __ENV.PROXY_ENDPOINT || 'structure';

export const options = {
    scenarios: {
        proxy_klassci_read: {
            executor: 'constant-vus',
            vus: Number(__ENV.PROXY_VUS) || 20,
            duration: __ENV.PROXY_DURATION || '2m',
        },
    },
    thresholds: {
        ...standardThresholds(SCENARIO_TAG),
    },
};

/**
 * Une itération = une requête `GET /api/proxy/{PROXY_ENDPOINT}` authentifiée par
 * un utilisateur distinct par VU (D6).
 */
export default function () {
    const user = fixtures.users[(__VU - 1) % fixtures.users.length];

    const res = authenticatedGet(`/api/proxy/${PROXY_ENDPOINT}`, user, {
        tags: { scenario: SCENARIO_TAG },
    });

    // Couvre déjà le statut HTTP, l'enveloppe {success:true} et l'absence de clé
    // `errors` non vide (signal d'erreur de proxification, Requirement 4.3) — aucune
    // logique de check supplémentaire n'est nécessaire ici (lib/checks.js).
    expectEnvelopeSuccess(res, 200, { scenario: SCENARIO_TAG });
}

/**
 * Délègue l'écriture du résumé de fin de tir à `lib/summary.js` (Requirement 1.2) :
 * résumé texte standard sur stdout + export JSON nommé selon la convention du
 * harnais (`proxy-klassci-read__<timestamp>[__<run-label>].json`).
 */
export function handleSummary(data) {
    return writeScenarioSummary(data, 'proxy-klassci-read');
}
