// tests/load/lib/thresholds.js
//
// Factory de seuils k6 partagée par TOUS les scénarios de charge (Requirement 1.2 —
// tout nouveau scénario doit réutiliser cet utilitaire plutôt que de redéfinir sa
// propre logique de seuils ad hoc, cf. design.md §2 "Convention de réutilisation").
//
// Rôle : générer, pour un scénario donné, un objet `thresholds` k6 exploitant la
// syntaxe native de filtrage par tag (`metric_name{tag_name:tag_value}`) afin que
// les métriques de latence (p50/p95/p99) et le taux d'erreur de CE scénario ne
// soient jamais agrégés avec ceux d'un autre scénario exécuté dans le même run
// (Requirements 2.4, 3.3, 4.4, 5.3 — "consigner séparément, sans les agréger").
//
// Contrat d'intégration à la charge de l'appelant (chaque fichier scenarios/*.js) :
//   1. Taguer CHAQUE requête HTTP du scénario avec `{ scenario: scenarioTag }`
//      (paramètre `tags` de k6/http, ex. `http.get(url, { tags: { scenario: 'seances' } })`),
//      avec exactement la même valeur que le `scenarioTag` passé à `standardThresholds()`.
//   2. Propager ce même tag aux appels `check()` (lib/checks.js, 3e argument de
//      `check(res, checks, tags)`), car la métrique `checks` n'hérite PAS
//      automatiquement des tags de la requête HTTP qui la précède.
//   Sans ces deux tags posés côté appelant, k6 ne trouve aucune série de données
//   correspondant à `http_req_duration{scenario:<tag>}` / `checks{scenario:<tag>}` :
//   le seuil reste alors sans mesure et n'apparaît pas comme un signal exploitable
//   dans le résumé — ce n'est pas un défaut de cette factory mais un rappel de son
//   contrat d'usage, documenté aussi dans docs/LOAD_TESTING.md.
//
// Le scénario `login.js` (Requirement 2.3) N'UTILISE PAS cette factory : son seuil
// (`login_unexpected_error: ['rate<0.01']`) porte sur une métrique Rate custom qui
// traite explicitement le 429 (rate-limit IP attendu) comme un succès, une sémantique
// incompatible avec le taux d'échec générique ci-dessous — voir design.md §3.1 et §6.

/** Taux d'échec maximal toléré par défaut (1 %), Requirement 2.3 ("ex. 1 %"). */
const DEFAULT_ERROR_RATE = 0.01;

// Seul chiffre de latence explicitement posé par la spec : la cible p95 < 300 ms de
// l'épique scalabilité #381 (requirements.md Introduction ; repris tel quel comme
// seuil de saturation par palier dans scenarios/ramp-up.js, design.md §3.6). Il sert
// ici de valeur par défaut cohérente pour p95, réutilisable par tous les scénarios
// unitaires — mais reste un défaut, pas une constante figée : surchargeable via
// `opts.p95Ms` sans modifier ce fichier (même principe que Requirement 7.6).
const DEFAULT_P95_MS = 300;

// p50 et p99 n'ont, eux, aucune valeur cible fixée par requirements.md/design.md pour
// les scénarios unitaires (contrairement au p95 ci-dessus). Leur fixer une "fausse"
// SLA inventée serait contraire à Requirement 10 (ne jamais faire passer un chiffre
// non mesuré pour une mesure valide). Ces deux seuils sont donc des PLAFONDS DE
// SANITY volontairement larges : ils ne doivent normalement jamais être atteints en
// usage nominal, mais existent pour (a) forcer k6 à calculer et afficher p(50)/p(99)
// dans le résumé de fin de tir (Requirements 2.4/3.3/4.4/5.3, "consigner séparément"),
// et (b) faire échouer explicitement le seuil en cas de dérive catastrophique
// (timeout réseau, endpoint qui raccroche) — pas en cas de dépassement léger d'une
// SLA non définie. Chacun reste surchargeable via `opts.p50Ms`/`opts.p99Ms`.
const DEFAULT_P50_SANITY_MS = 3000;
const DEFAULT_P99_SANITY_MS = 5000;

/**
 * Construit un objet `thresholds` k6 standard pour un scénario donné, entièrement
 * scopé par le tag `scenario:<scenarioTag>` — à fusionner dans
 * `export const options = { thresholds: { ...standardThresholds('seances') } }`.
 *
 * @param {string} scenarioTag - identifiant court et stable du scénario (ex. "seances",
 *   "proxy-klassci", "notifications", "dashboard") ; doit être IDENTIQUE à la valeur du
 *   tag `scenario` posé sur les requêtes HTTP et les checks du même fichier scénario.
 * @param {object} [opts]
 * @param {number} [opts.errorRate=0.01] - taux d'échec maximal toléré, entre 0 et 1
 *   exclus (0.01 = 1 %). Traduit en seuil `checks{scenario:...} rate>${1 - errorRate}`.
 * @param {number} [opts.p50Ms=3000] - plafond de sanity p50 en millisecondes (non une SLA, voir commentaire `DEFAULT_P50_SANITY_MS`).
 * @param {number} [opts.p95Ms=300] - seuil p95 en millisecondes, aligné par défaut sur la cible de l'épique #381.
 * @param {number} [opts.p99Ms=5000] - plafond de sanity p99 en millisecondes (non une SLA, voir commentaire `DEFAULT_P99_SANITY_MS`).
 * @returns {Record<string, string[]>} objet de seuils k6, clés déjà scopées par tag.
 * @throws {Error} si `scenarioTag` est absent/vide, ou si un des taux/plafonds numériques est hors domaine valide.
 */
export function standardThresholds(scenarioTag, opts = {}) {
    if (typeof scenarioTag !== 'string' || scenarioTag.trim() === '') {
        throw new Error(
            'standardThresholds: scenarioTag est requis (chaîne non vide) — ' +
                'un seuil non scopé par tag s\'agrégerait avec tous les scénarios du run (Requirements 2.4/3.3/4.4/5.3).'
        );
    }

    const {
        errorRate = DEFAULT_ERROR_RATE,
        p50Ms = DEFAULT_P50_SANITY_MS,
        p95Ms = DEFAULT_P95_MS,
        p99Ms = DEFAULT_P99_SANITY_MS,
    } = opts;

    if (!(errorRate > 0 && errorRate < 1)) {
        throw new Error(`standardThresholds: opts.errorRate doit être compris entre 0 et 1 exclus, reçu ${errorRate}`);
    }
    for (const [name, value] of [['p50Ms', p50Ms], ['p95Ms', p95Ms], ['p99Ms', p99Ms]]) {
        if (!(Number.isFinite(value) && value > 0)) {
            throw new Error(`standardThresholds: opts.${name} doit être un nombre fini strictement positif, reçu ${value}`);
        }
    }

    // 1 - errorRate arrondi pour éviter un artefact de flottant (ex. 1 - 0.03 -> 0.9700000000000001)
    // qui rendrait le message de seuil illisible dans le résumé k6.
    const minCheckRate = Number((1 - errorRate).toPrecision(10));

    return {
        [`http_req_duration{scenario:${scenarioTag}}`]: [
            `p(50)<${p50Ms}`,
            `p(95)<${p95Ms}`,
            `p(99)<${p99Ms}`,
        ],
        [`checks{scenario:${scenarioTag}}`]: [`rate>${minCheckRate}`],
    };
}
