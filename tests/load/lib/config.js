/**
 * Configuration partagée du harnais de charge k6 (#372).
 *
 * ## Pourquoi
 *
 * Chaque scénario (`tests/load/scenarios/*.js`) a besoin des mêmes trois
 * informations : contre quelle cible taper (`BASE_URL`), au bout de combien
 * de temps abandonner une requête (`HTTP_TIMEOUT`), et sous quel nom écrire
 * son résumé de résultats (`resultFileName()`). Centraliser cette résolution
 * ici évite que chacun des 6 scénarios redéfinisse sa propre lecture de
 * `__ENV` avec des valeurs par défaut divergentes — DRY imposé par le design
 * (`design.md` §"Composants" section 1, Requirement 1.2).
 *
 * Ce fichier ne fait AUCUN appel réseau et ne dépend d'aucun autre module du
 * harnais : il est le premier import de tout scénario.
 *
 * ## Convention de nommage des résultats (Requirements 1.1, 11.2)
 *
 * `tests/load/results/<scenario-slug>__<YYYYMMDDTHHmmssZ>[__<run-label>].json`
 *
 * - `<scenario-slug>` : identifiant court et stable du scénario (ex.
 *   `login`, `seances-list`, `ramp-up`), fourni par l'appelant.
 * - `<YYYYMMDDTHHmmssZ>` : horodatage UTC à la seconde, sans séparateurs —
 *   garantit qu'aucun tir ne peut écraser le résultat d'un tir précédent
 *   (deux tirs de charge ne se répètent jamais à la même seconde).
 * - `[__<run-label>]` : segment optionnel, alimenté par la variable
 *   d'environnement `LOAD_TEST_RUN_LABEL` (ex. `baseline-pre-374`,
 *   `post-378-octane`), pour tracer explicitement à quelle étape du plan de
 *   scalabilité (#367/#374/#378/#379/#380) un fichier de résultat correspond.
 */

/**
 * URL de base de l'API ciblée par le tir de charge.
 *
 * Toujours un paramètre explicite lu depuis `__ENV.BASE_URL` — jamais une
 * valeur en dur pointant vers un environnement de production (Requirement
 * 12.2). La valeur par défaut cible le serveur de développement local
 * (`php artisan serve`, port 8000 — ne collationne pas avec le stub Klassci
 * sur le port 8089, cf. `design.md` §5.3).
 *
 * @type {string}
 */
export const BASE_URL = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/+$/, '');

/**
 * Délai maximal (chaîne k6, ex. `'30s'`) accordé à chaque requête HTTP avant
 * d'être considérée en échec. Partagé par tous les scénarios via
 * `lib/http.js`, configurable sans modification de code (Requirement 7.6,
 * même principe appliqué ici pour cohérence).
 *
 * @type {string}
 */
export const HTTP_TIMEOUT = __ENV.HTTP_TIMEOUT || '30s';

/**
 * Répertoire de sortie des résumés de tir (JSON) et des captures CPU/RAM
 * (CSV), relatif à la racine du dépôt. Le harnais doit toujours être lancé
 * depuis la racine du projet (documenté dans `docs/LOAD_TESTING.md`).
 *
 * Exclu du contrôle de version à l'exception de `.gitkeep`
 * (`.gitignore:114-115`, Requirement 1.3).
 *
 * @type {string}
 */
export const RESULTS_DIR = 'tests/load/results';

/**
 * Label optionnel du tir en cours, pour distinguer sans ambiguïté les
 * résultats de deux tirs successifs (Requirement 11.2). Vide si non fourni —
 * dans ce cas `resultFileName()` omet le segment correspondant plutôt que
 * d'insérer une chaîne vide entre deux `__`.
 *
 * @type {string}
 */
export const RUN_LABEL = (__ENV.LOAD_TEST_RUN_LABEL || '').trim();

/**
 * Ajoute un zéro de tête si nécessaire pour obtenir une largeur fixe.
 *
 * @param {number} value
 * @param {number} [width]
 * @returns {string}
 */
function pad(value, width = 2) {
  return String(value).padStart(width, '0');
}

/**
 * Formate une date en horodatage UTC compact `YYYYMMDDTHHmmssZ`, sans
 * séparateurs, tel qu'exigé par la convention de nommage des résultats
 * (Requirement 11.2).
 *
 * @param {Date} [date] Date à formater (par défaut : l'instant présent).
 * @returns {string}
 */
export function formatTimestampForFilename(date = new Date()) {
  return (
    date.getUTCFullYear().toString() +
    pad(date.getUTCMonth() + 1) +
    pad(date.getUTCDate()) +
    'T' +
    pad(date.getUTCHours()) +
    pad(date.getUTCMinutes()) +
    pad(date.getUTCSeconds()) +
    'Z'
  );
}

/**
 * Construit le nom de fichier de résultat d'un scénario, conforme à la
 * convention `<scenario-slug>__<YYYYMMDDTHHmmssZ>[__<run-label>].<extension>`
 * (Requirements 1.1, 11.2). Utilisé par `lib/summary.js` pour tous les
 * scénarios, et par tout nouveau scénario ajouté ultérieurement — jamais
 * réimplémenté localement (Requirement 1.2).
 *
 * @param {string} scenarioSlug Identifiant court du scénario, ex. `'login'`,
 *   `'seances-list'`, `'proxy-klassci-read'`, `'notifications'`,
 *   `'dashboard-stats'`, `'ramp-up'`.
 * @param {string} [extension] Extension du fichier sans le point (défaut :
 *   `'json'`, seul format produit par les scénarios k6 eux-mêmes — les
 *   scripts de capture CPU/RAM en `.csv` ne dépendent pas de ce module).
 * @param {Date} [date] Date à utiliser pour l'horodatage (défaut : l'instant
 *   présent) — paramètre injectable pour les tests unitaires du harnais.
 * @returns {string} Nom de fichier relatif, ex.
 *   `'login__20260704T141005Z__baseline-pre-374.json'`.
 */
export function resultFileName(scenarioSlug, extension = 'json', date = new Date()) {
  if (!scenarioSlug) {
    throw new Error('resultFileName() requiert un scenarioSlug non vide.');
  }

  const segments = [scenarioSlug, formatTimestampForFilename(date)];
  if (RUN_LABEL) {
    segments.push(RUN_LABEL);
  }

  return `${segments.join('__')}.${extension}`;
}

/**
 * Construit le chemin complet (relatif à la racine du dépôt) du fichier de
 * résultat d'un scénario, dans `RESULTS_DIR`.
 *
 * @param {string} scenarioSlug Voir {@link resultFileName}.
 * @param {string} [extension] Voir {@link resultFileName}.
 * @param {Date} [date] Voir {@link resultFileName}.
 * @returns {string} Chemin relatif, ex.
 *   `'tests/load/results/login__20260704T141005Z.json'`.
 */
export function resultFilePath(scenarioSlug, extension = 'json', date = new Date()) {
  return `${RESULTS_DIR}/${resultFileName(scenarioSlug, extension, date)}`;
}
