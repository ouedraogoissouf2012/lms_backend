# ADR-906-01 — Le motif d'un refus, dans le corps

**Date :** 2026-09-26  
**Statut :** accepté  
**Issue :** #906 · s'appuie sur le précédent `klassci_session_expired` (`RendersKlassciProxyErrors`) · corrigé par #924

## Décision

Quand un même statut HTTP porte plusieurs sens, la réponse porte un **motif** :

```json
{ "success": false, "message": "…", "reason": "enrolment_not_active" }
```

- **Dans le corps**, clé `reason` au premier niveau. **Jamais dans un en-tête.**
- **Identifiant stable**, `snake_case` anglais — la forme déjà employée par `klassci_session_expired`, `not_enrolled`, `actor_not_authorized`.
- **Omis** quand le refus n'a qu'un sens : aucun JSON existant ne change.

Porté par `BusinessException::$reason`, que le contrôleur passe à `RespondsWithJson::errorResponse(…, reason: $e->reason)` ; pour un seau de débit, par `Limit::response()`. Le trait reçoit une **chaîne**, jamais une exception : l'exigence R4 de la spec d'enveloppe — aucun point d'entrée pour `$e->getMessage()` — reste entière, et son contrat de référence est amendé en conséquence (`.claude/specs/api-response-envelope/design.md` §4.3). Tout 429 de débit — avec ou sans motif — sort d'un seul constructeur, `TooManyRequestsPresenter::json()` : le texte et la forme ne peuvent plus dériver entre le rendu global et les seaux motivés.

Premier périmètre :

| Statut | Porte | `reason` |
|---|---|---|
| 409 | anonyme | `account_exists` |
| 409 | authentifiée | `enrolment_not_active`, `no_institution` |
| 429 | authentifiée | `account_quota_exceeded` (minute), `account_daily_cap_reached` (jour), `institution_cap_reached` |
| 429 | anonyme | `ip_quota_exceeded`, `global_cap_reached` |
| 409 | `GET /classes` (#924) | `no_institution` — même sens que sur la porte authentifiée |

## Pourquoi

**Le chemin central d'erreur du front ne lit pas le message du serveur** (hors 422) : `normalizeError` (`frontend_lms` `src/services/errorHandler.js:46-48`) traduit un statut par son catalogue, pour ne rien fuiter. Un statut à plusieurs sens le forçait à choisir un message faux — un 409 « inscription non active » s'affichait « erreur inattendue », et l'apprenant allait au support pour une décision de son établissement.

Ce n'est vrai **que** de ce chemin : 32 endroits de `src/` (tronc `dev`, `c7c86d9d`) lisent `response.data.message` directement. Le motif sert les écrans qui passent par `normalizeError` — dont les écrans d'inscription à venir ; il ne rend pas le message inutile ailleurs.

**Tension avec ADR-845-01**, écrit quatre jours plus tôt : une transition de période refusée porte « le statut courant dans le message ». Sous la prémisse ci-dessus, cette information n'atteint pas un écran qui passe par `normalizeError`. Aucune des deux décisions n'annule l'autre ; si un écran de période doit la montrer, c'est un motif qu'il lui faudra.

**Pas dans un en-tête** : le front de production est cross-origin et `config/cors.php` déclare `exposed_headers: []`. Tout en-tête non standard y est invisible au navigateur, alors qu'il est lisible en dev via le proxy Vite. frontend_lms#426 est ce défaut, déjà livré, pour `Retry-After`.

## Le piège mesuré

`Limit::response()` lève une `HttpResponseException`. Le rendu générique `\Throwable` de `bootstrap/app.php` passe **avant** Laravel (`Handler:618` puis `:623`) : mesuré à travers le Handler réel, cette exception ressortait en **500 muet, `Retry-After` perdu**. Un rendu dédié la rend désormais telle quelle — le comportement documenté de Laravel.

## Écarté

- **Déduire le motif dans le rendu global du 429** : il ignore quel seau a refusé.
- **`error_code`** : exigé par le schéma `ErrorResponse` mais envoyé par aucun endpoint ; le précédent réellement lu par le front est `reason`.
- **Une énumération des motifs** (recommandée par l'audit de sécurité, pour qu'aucun appelant ne passe `reason: $e->getMessage()`) : les treize motifs que le dépôt émettait déjà en HTTP sont des littéraux (voir « Précédents ») ; une énumération réservée aux huit de ce périmètre créerait une seconde convention. À reprendre si l'on type **tous** les motifs de l'API à la fois. Côté contrat, `RefusMotive.reason.enum` ne ferme que les motifs de ce périmètre, pas ceux de l'API.

## Précédents

Avant #906, le dépôt émettait déjà un motif dans le corps, sous trois formes :

- **`reason`, `snake_case` anglais** — `klassci_session_expired` (`RendersKlassciProxyErrors:67`, **seulement** pour l'expiration de session : `RendersKlassciProxyErrorsTest` refuse de l'élargir au 403), et les neuf motifs de `ParticipantValidationService` (`not_enrolled`, `no_classe_id`, `actor_not_authorized`, `invalid_role`…). C'est la forme retenue.
- **`reason` en français** — `injoignable`, `pas_klassci`, `klassci_erreur` (`InstitutionConnectionTester:93`, via `ApplicationProofResult`). Même clé, autre langue : non migré ici.
- **`code`** — `ImportPreviewController:49` (`ImportPreviewFailed::machineCode()`). Autre clé pour le même rôle : non migré ici.

## Ce qui reste

- **Un contrôleur qui ne passe pas `reason:` supprime le motif sans signal.** Les deux portes d'inscription le passent ; les sept autres relais de `BusinessException` (#898) ne le font pas, et perdraient un motif qu'un service poserait demain.

- **Le 429 `institution_cap_reached` ne porte pas `Retry-After`** : il vient d'un refus métier, pas d'un seau. Son motif dit déjà « jusqu'au lendemain ». Il porte en revanche `X-RateLimit-*` — ceux du seau du **compte**, que le middleware pose sur toute réponse qui l'a traversé (mesuré, #924).

## Deux erreurs de ce ticket, corrigées avant fusion

Relevées en relisant la mémoire du projet, que j'avais sautée :

- **Un point d'entrée pour `getMessage()` dans le trait.** Le premier jet ajoutait `businessErrorResponse(BusinessException)`, contraire à R4 d'une spec approuvée que je n'avais pas lue. Remplacé par le paramètre `reason` d'`errorResponse()`.
- **Un nettoyage des seaux entre tests qui existait déjà.** `Tests\TestCase::setUp()` vide Redis avant chaque test (#374). Le trait de nettoyage, et le `tearDown` que #885 avait posé sur la même prémisse fausse, sont retirés.

## Corrigé après fusion (#924, 2026-09-27)

Relevé par une relecture de tout le code, après fusion :

- **La prémisse était trop large.** « Le front ne lit jamais le message » n'est vrai que du chemin `normalizeError` ; corrigée ci-dessus, avec la tension envers ADR-845-01 qu'elle masquait.
- **Les précédents étaient omis**, et leur compte faux (« cinq » ; treize mesurés). Section « Précédents » ajoutée ; « la liste fermée existe déjà côté contrat » retiré — elle ne ferme que ce périmètre.
- **Un motif pour deux sens.** Les deux bornes du seau du compte disaient `account_quota_exceeded` ; la borne du jour dit désormais `account_daily_cap_reached`, sur la règle de la porte anonyme (`*_quota_exceeded` / `*_cap_reached`).
- **Une réserve rangée parmi les erreurs.** L'absence de `Retry-After` sur `institution_cap_reached` n'en est pas une : elle est passée dans « Ce qui reste ».

## Ce qui ferait changer d'avis

Qu'un client ait besoin du motif sur un statut qui n'a qu'un sens — il faudrait alors toujours l'émettre, et la règle d'omission tomberait.
