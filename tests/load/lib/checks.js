/**
 * lib/checks.js — Vérification du contrat d'enveloppe JSON de l'API (harnais k6, issue #372).
 *
 * Contrat vérifié : celui produit par `RespondsWithJson::successResponse()`
 * (`app/Http/Controllers/Concerns/RespondsWithJson.php:63-84`) :
 *
 *   { success: true, message?: string, data?: mixed, meta?: object }
 *
 * Le trait OMET `message`/`data`/`meta` quand ils sont vides (`message === ''`,
 * `data === null`, `meta === []` — cf. RespondsWithJson.php:71-81). Cette
 * fonction ne les exige donc JAMAIS : elle vérifie uniquement leur forme
 * quand ils sont présents, et n'échoue pas si l'une de ces clés est absente.
 *
 * `errors` appartient uniquement au contrat d'erreur (`errorResponse()`,
 * RespondsWithJson.php:96-111) : sa présence non vide sur une réponse de
 * succès signale une anomalie de proxification (Requirement 4.3) et fait
 * donc échouer le check.
 *
 * Convention du harnais (Requirement 1.2, design.md §2) : TOUT scénario k6 de
 * `tests/load/scenarios/` DOIT réutiliser `expectEnvelopeSuccess()` pour
 * valider une réponse de succès plutôt que de redéfinir son propre check
 * d'enveloppe JSON.
 *
 * @see app/Http/Controllers/Concerns/RespondsWithJson.php
 */
import { check } from 'k6';
import { Rate } from 'k6/metrics';

/**
 * Taux d'échec de conformité au contrat d'enveloppe (statut HTTP inattendu,
 * JSON invalide, `success` absent/faux, `errors` non vide sur un succès...).
 *
 * Exportée (pas seulement interne à ce module) pour que `lib/thresholds.js`
 * puisse lui adosser un seuil dédié (ex. `envelope_errors: ['rate<0.01']`)
 * sans qu'aucun scénario n'ait à redéclarer sa propre métrique — même
 * logique DRY que le reste de `lib/` (Requirement 1.2).
 */
export const envelopeErrors = new Rate('envelope_errors');

/**
 * Vérifie qu'une réponse HTTP respecte le contrat de succès de
 * `RespondsWithJson` et enregistre le résultat dans la métrique
 * `envelope_errors`.
 *
 * Checks k6 individuels (chacun visible séparément dans le résumé k6,
 * Requirements 2.2/3.2) :
 *  - le statut HTTP correspond exactement à `expectedStatus` ;
 *  - le corps de la réponse est un JSON syntaxiquement valide ;
 *  - la clé `success` vaut strictement `true` ;
 *  - si `message` est présente, elle est de type `string` ;
 *  - si `data` est présente, elle n'est pas `null` (RespondsWithJson.php:75-77
 *    n'ajoute la clé que lorsque `$data !== null` — sa présence garantit donc
 *    une valeur non nulle par construction du contrat) ;
 *  - si `meta` est présente, c'est un objet (non un tableau, non `null`) ;
 *  - `errors` est absente ou vide — sa présence non vide sur une réponse de
 *    succès est le signal d'erreur de proxification couvert par le
 *    Requirement 4.3.
 *
 * @param {import('k6/http').RefinedResponse<any>} res - Réponse k6 (`http.get`/`http.post`...).
 * @param {number} expectedStatus - Code HTTP de succès attendu (200, 201...).
 * @param {object} [tags] - Tags k6 (ex. `{ scenario: 'seances' }`), à faire correspondre
 *   EXACTEMENT à ceux passés à `standardThresholds()` (`lib/thresholds.js`) : la métrique
 *   `checks{scenario:X}` qu'un seuil scope par tag n'existe que si ce 3e argument de
 *   `check()` porte le même tag que la requête HTTP correspondante (contrat documenté
 *   dans `lib/thresholds.js`).
 * @returns {boolean} `true` si TOUS les checks ci-dessus passent, `false` sinon.
 */
export function expectEnvelopeSuccess(res, expectedStatus, tags = {}) {
    let body = null;
    let isValidJson = true;

    try {
        body = res.json();
    } catch (error) {
        isValidJson = false;
    }

    const hasBody = isValidJson && body !== null && typeof body === 'object';

    const passed = check(res, {
        [`statut HTTP egal a ${expectedStatus}`]: (r) => r.status === expectedStatus,
        'corps JSON syntaxiquement valide': () => isValidJson,
        'success vaut strictement true': () => hasBody && body.success === true,
        'message est une chaine quand presente': () =>
            !hasBody || body.message === undefined || typeof body.message === 'string',
        'data est non nulle quand presente': () =>
            !hasBody || body.data === undefined || body.data !== null,
        'meta est un objet quand presente': () =>
            !hasBody ||
            body.meta === undefined ||
            (typeof body.meta === 'object' && body.meta !== null && !Array.isArray(body.meta)),
        'errors absente ou vide (pas de proxification en erreur)': () =>
            !hasBody ||
            body.errors === undefined ||
            (typeof body.errors === 'object' && body.errors !== null && Object.keys(body.errors).length === 0),
    }, tags);

    envelopeErrors.add(!passed, tags);

    return passed;
}
