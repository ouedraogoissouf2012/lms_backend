/**
 * lib/summary.js — Résumé de fin de tir partagé par tous les scénarios k6 (#372).
 *
 * ## Pourquoi
 *
 * k6 n'imprime le résumé texte standard de fin de tir QUE si le script
 * n'exporte aucun `handleSummary()`, ou si l'objet retourné par
 * `handleSummary()` contient explicitement une clé `stdout` (doc officielle
 * k6, "Custom summary" — https://k6.io/docs/results-output/end-of-test/custom-summary/).
 * Comme chaque scénario doit AUSSI écrire un export JSON nommé selon la
 * convention du harnais (Requirement 7.4 : « sortie k6 standard ET/OU export
 * JSON/CSV » ; Requirement 11.2 : nommage non ambigu entre tirs successifs),
 * reproduire ce texte standard nécessite `k6-summary`, la bibliothèque
 * officiellement documentée par k6 pour cet usage précis (aucune fonction
 * native équivalente n'existe dans `k6` core) — importée une seule fois ici,
 * jamais dupliquée par un scénario (Requirement 1.2).
 *
 * Convention de réutilisation (Requirement 1.2, `design.md` §2 "lib/") : TOUT
 * scénario de `tests/load/scenarios/` DOIT déléguer son `handleSummary(data)`
 * à `writeScenarioSummary()` plutôt que de réimplémenter l'écriture du
 * résumé :
 *
 *   import { writeScenarioSummary } from '../lib/summary.js';
 *   export function handleSummary(data) {
 *       return writeScenarioSummary(data, 'login');
 *   }
 *
 * Le nommage du fichier de sortie réutilise `resultFilePath()` de
 * `lib/config.js` (tâche 2.1, déjà écrite — voir `config.js:150-152`), qui
 * compose elle-même `resultFileName()` : la convention de nommage
 * `<scenario-slug>__<YYYYMMDDTHHmmssZ>[__<run-label>].json`
 * (`design.md` §Data Models, Requirements 1.1/11.2) n'est donc JAMAIS
 * dupliquée dans ce fichier.
 *
 * @see .claude/specs/372-k6-load-testing/design.md §Components and Interfaces/2, §Data Models
 */
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.2/index.js';
import { resultFilePath } from './config.js';

/**
 * Construit l'objet attendu par le hook `handleSummary` de k6 : le résumé
 * texte standard sur stdout (Requirement 7.4, « sortie k6 standard ») ET un
 * export JSON complet des métriques du tir, nommé selon la convention du
 * harnais (Requirements 1.1, 11.2), quel que soit le flag CLI `--out`
 * par ailleurs utilisé au lancement de `k6 run`.
 *
 * @param {object} data - Objet de métriques fourni par k6 au hook `handleSummary`
 *   (contient notamment `data.metrics`, y compris les sous-métriques scopées par
 *   tag référencées dans les `thresholds` du scénario, ex. `checks{scenario:login}`).
 * @param {string} scenarioSlug - Slug court et stable du scénario, ex. `'login'`,
 *   `'seances-list'`, `'proxy-klassci-read'`, `'notifications'`, `'dashboard-stats'`,
 *   `'ramp-up'` — doit correspondre au fichier appelant, pour que le nom du fichier
 *   de résultat corresponde sans ambiguïté aux métriques qu'il contient.
 * @returns {Record<string, string>} Mapping cible → contenu, au format exigé par le
 *   hook `handleSummary` de k6 : la clé `stdout` pour la console, et un chemin relatif
 *   (`tests/load/results/<scenario-slug>__<timestamp>[__<run-label>].json`) pour le
 *   fichier JSON écrit sur disque.
 * @throws {Error} propagée par `resultFilePath()`/`resultFileName()` si `scenarioSlug`
 *   est absent ou vide (voir `lib/config.js`).
 */
export function writeScenarioSummary(data, scenarioSlug) {
    const outputPath = resultFilePath(scenarioSlug);

    return {
        stdout: textSummary(data, { indent: ' ', enableColors: true }),
        [outputPath]: JSON.stringify(data, null, 2),
    };
}
