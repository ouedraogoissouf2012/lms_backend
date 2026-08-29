/**
 * lib/fixtures.js — Chargement et garde d'utilisation de la fixture de test (#372).
 *
 * ## Pourquoi
 *
 * Tous les scénarios authentifiés (`scenarios/seances-list.js`, `notifications.js`,
 * `dashboard-stats.js`, `proxy-klassci-read.js`) et le scénario `login.js` ont besoin
 * de la MÊME fixture — `setup/fixtures/load-test-users.json`, générée par
 * `setup/prepare-load-test-data.php` (Requirement 6.4) — pour obtenir des tokens
 * Sanctum, un pool d'identifiants de login et l'URL du stub Klassci. Centraliser son
 * chargement et sa validation ici évite que chaque scénario réimplémente sa propre
 * lecture de fichier `open()` avec une gestion d'erreur divergente (Requirement 1.2).
 *
 * `open()` n'est utilisable qu'en contexte d'init k6 (exécuté une fois par VU, avant
 * le début des itérations) — `loadFixtures()` DOIT donc être appelée au niveau
 * module de chaque fichier scénario, jamais depuis une fonction exportée exécutée
 * pendant la phase VU (`export default function () { ... }`).
 *
 * ## Garde D4 — allowlist loopback (Requirement 4.2)
 *
 * `assertKlassciStubIsLoopback()` implémente la garde anti-Klassci-réel du design
 * (`design.md` §"Décisions architecturales structurantes", D4) : une ALLOWLIST des
 * hosts loopback (`127.0.0.1`, `localhost`, `::1`), pas une blocklist de domaines
 * Klassci connus — une blocklist serait par nature incomplète (un nouveau domaine
 * Klassci non listé la contournerait silencieusement), alors qu'une allowlist du
 * loopback est sûre par construction. Si le host résolu depuis
 * `fixtures.klassci_stub_url` n'appartient pas à cette allowlist, `exec.test.abort()`
 * (module `k6/execution`) arrête l'intégralité du tir AVANT qu'aucune requête ne
 * parte — un empêchement structurel, pas une simple alerte dans les logs.
 *
 * @see .claude/specs/372-k6-load-testing/design.md §Components and Interfaces/2, D4
 */

import exec from 'k6/execution';

/**
 * Chemin de la fixture générée par `setup/prepare-load-test-data.php`, relatif à ce
 * fichier (résolution `open()` de k6 — toujours relative au script appelant, jamais
 * au répertoire de travail du process `k6 run`). Répertoire entièrement gitignored
 * (`.gitignore:116`, Requirement 12.1) : ce fichier ne contient jamais de secret
 * committé, il n'existe que localement après exécution du script de préparation.
 *
 * @type {string}
 */
const FIXTURES_PATH = '../setup/fixtures/load-test-users.json';

/**
 * Hosts considérés comme le stub Klassci local (D2) et donc autorisés comme cible
 * du scénario proxy Klassci. Toute autre valeur — y compris un domaine Klassci réel
 * connu — est refusée par construction (D4).
 *
 * @type {string[]}
 */
const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1'];

/**
 * Extrait le nom d'hôte d'une URL absolue `http(s)://...` sans dépendre d'un objet
 * `URL` global (dont la disponibilité varie selon la version de k6) : une extraction
 * par expression régulière reste correcte quelle que soit la version du binaire k6
 * utilisée en local ou sur le futur VPS (#367).
 *
 * Gère le cas d'un littéral IPv6 entre crochets (`http://[::1]:8089`) en retirant les
 * crochets, afin de comparer une valeur homogène à `::1` dans `LOOPBACK_HOSTS`.
 *
 * @param {unknown} urlString - Valeur à parser (typiquement `fixtures.klassci_stub_url`).
 * @returns {string|null} Le nom d'hôte en minuscules, ou `null` si `urlString` n'est
 *   pas une chaîne exploitable ou ne correspond pas à une URL absolue reconnaissable.
 */
function extractHost(urlString) {
    if (typeof urlString !== 'string' || urlString.trim() === '') {
        return null;
    }

    const match = urlString.match(/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/(?:[^@/]*@)?(\[[^\]]+\]|[^/:?#]+)/);
    if (!match) {
        return null;
    }

    const hostPart = match[1];
    const host = hostPart.startsWith('[') && hostPart.endsWith(']') ? hostPart.slice(1, -1) : hostPart;

    return host.toLowerCase();
}

/**
 * Valide la structure minimale attendue de la fixture (schéma documenté dans
 * `design.md` §Data Models), afin qu'une fixture tronquée, périmée ou corrompue
 * échoue explicitement au chargement plutôt que de provoquer une erreur cryptique
 * (`Cannot read property 'token' of undefined`) au milieu d'un tir de charge.
 *
 * @param {unknown} fixtures - Contenu JSON.parse() de la fixture.
 * @returns {string[]} Liste des messages d'erreur de validation (vide si valide).
 */
function collectValidationErrors(fixtures) {
    const errors = [];

    if (!fixtures || typeof fixtures !== 'object' || Array.isArray(fixtures)) {
        return ['la fixture doit être un objet JSON (reçu : ' + JSON.stringify(fixtures) + ')'];
    }

    if (typeof fixtures.klassci_stub_url !== 'string' || fixtures.klassci_stub_url.trim() === '') {
        errors.push('"klassci_stub_url" est manquant ou vide');
    }

    if (!fixtures.institution || typeof fixtures.institution !== 'object' || !fixtures.institution.slug) {
        errors.push('"institution.slug" est manquant');
    }

    if (!Array.isArray(fixtures.users) || fixtures.users.length === 0) {
        errors.push('"users" doit être un tableau non vide (au moins 1 utilisateur de test)');
    } else {
        fixtures.users.forEach((user, index) => {
            if (!user || typeof user.token !== 'string' || user.token.trim() === '') {
                errors.push(`"users[${index}].token" est manquant ou vide`);
            }
        });
    }

    if (!Array.isArray(fixtures.login_pool) || fixtures.login_pool.length === 0) {
        errors.push('"login_pool" doit être un tableau non vide (identifiants pour scenarios/login.js)');
    } else {
        fixtures.login_pool.forEach((credentials, index) => {
            if (!credentials || !credentials.username || !credentials.password) {
                errors.push(`"login_pool[${index}]" doit contenir "username" et "password"`);
            }
        });
    }

    return errors;
}

/**
 * Charge et valide `setup/fixtures/load-test-users.json`, produite par
 * `setup/prepare-load-test-data.php` (Requirement 6.4).
 *
 * À appeler UNE FOIS au niveau module de chaque fichier scénario (contexte d'init
 * k6, exécuté par VU avant le début des itérations) :
 *
 * ```js
 * import { loadFixtures } from '../lib/fixtures.js';
 * const fixtures = loadFixtures();
 * export default function () { ... utiliser fixtures.users, fixtures.login_pool ... }
 * ```
 *
 * @returns {object} La fixture désérialisée et validée (voir schéma `design.md` §Data Models).
 * @throws {Error} Si le fichier est introuvable (script de préparation non exécuté),
 *   si son contenu n'est pas un JSON valide, ou si sa structure ne satisfait pas
 *   {@link collectValidationErrors} — dans tous les cas, message explicite renvoyant
 *   vers `setup/prepare-load-test-data.php` plutôt qu'une erreur cryptique en cours de tir.
 */
export function loadFixtures() {
    let raw;
    try {
        raw = open(FIXTURES_PATH);
    } catch (error) {
        throw new Error(
            `[lib/fixtures.js] Impossible de lire la fixture "${FIXTURES_PATH}" ` +
                `(résolu relativement à tests/load/lib/). Exécutez d'abord ` +
                `"php tests/load/setup/prepare-load-test-data.php" avant de lancer ce scénario. ` +
                `Erreur d'origine : ${error}`
        );
    }

    let fixtures;
    try {
        fixtures = JSON.parse(raw);
    } catch (error) {
        throw new Error(
            `[lib/fixtures.js] La fixture "${FIXTURES_PATH}" n'est pas un JSON valide. ` +
                `Régénérez-la via "php tests/load/setup/prepare-load-test-data.php". ` +
                `Erreur d'origine : ${error}`
        );
    }

    const errors = collectValidationErrors(fixtures);
    if (errors.length > 0) {
        throw new Error(
            `[lib/fixtures.js] Fixture "${FIXTURES_PATH}" invalide :\n` +
                errors.map((message) => `  - ${message}`).join('\n') +
                '\nRégénérez-la via "php tests/load/setup/prepare-load-test-data.php".'
        );
    }

    return fixtures;
}

/**
 * Garde D4 (Requirement 4.2) : empêche structurellement le lancement d'un scénario
 * qui appellerait `KlassciProxyService` via un host non-loopback — en particulier
 * un domaine Klassci réel de production. À appeler au niveau module, juste après
 * {@link loadFixtures}, par tout scénario qui déclenche effectivement un appel au
 * stub Klassci (`scenarios/proxy-klassci-read.js`, et `scenarios/ramp-up.js` s'il
 * venait à inclure le proxy dans son mix) :
 *
 * ```js
 * const fixtures = loadFixtures();
 * assertKlassciStubIsLoopback(fixtures); // exec.test.abort() si non-loopback
 * ```
 *
 * N'est PAS appelée par les scénarios qui ne touchent jamais le stub (séances,
 * notifications, dashboard, login) : ces endpoints ne traversent `EnsureKlassciSync`
 * sans appel externe que parce que la fixture est générée avec `last_klassci_sync`
 * frais (voir `design.md` §Architecture, "Point clé").
 *
 * @param {{ klassci_stub_url?: unknown }} fixtures - Fixture chargée par {@link loadFixtures}.
 * @returns {void} Ne retourne rien si la garde passe ; appelle `exec.test.abort()`
 *   (qui interrompt le test — le contrôle ne revient jamais à l'appelant) sinon.
 */
export function assertKlassciStubIsLoopback(fixtures) {
    const stubUrl = fixtures && fixtures.klassci_stub_url;
    const host = extractHost(stubUrl);

    if (!host || LOOPBACK_HOSTS.indexOf(host) === -1) {
        exec.test.abort(
            `[lib/fixtures.js] Garde anti-Klassci-réel (D4, Requirement 4.2) : ` +
                `"klassci_stub_url" ("${stubUrl}") ne pointe pas vers un host loopback ` +
                `(${LOOPBACK_HOSTS.join(', ')}). Ce scénario appelle KlassciProxyService et ne doit ` +
                `JAMAIS être exécuté contre un host externe, en particulier l'API Klassci réelle. ` +
                'Tir arrêté avant l\'envoi de toute requête HTTP.'
        );
    }
}
