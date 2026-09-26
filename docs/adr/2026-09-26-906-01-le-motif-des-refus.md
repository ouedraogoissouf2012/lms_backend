# ADR-906-01 — Le motif d'un refus, dans le corps

**Date :** 2026-09-26  
**Statut :** accepté  
**Issue :** #906 · s'appuie sur le précédent `klassci_session_expired` (`RendersKlassciProxyErrors`)

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
| 429 | authentifiée | `account_quota_exceeded`, `institution_cap_reached` |
| 429 | anonyme | `ip_quota_exceeded`, `global_cap_reached` |

## Pourquoi

**Le front ne lit jamais le message du serveur** (hors 422) : il traduit un statut par son catalogue, pour ne rien fuiter. Un statut à plusieurs sens le forçait à choisir un message faux — un 409 « inscription non active » s'affichait « erreur inattendue », et l'apprenant allait au support pour une décision de son établissement.

**Pas dans un en-tête** : le front de production est cross-origin et `config/cors.php` déclare `exposed_headers: []`. Tout en-tête non standard y est invisible au navigateur, alors qu'il est lisible en dev via le proxy Vite. frontend_lms#426 est ce défaut, déjà livré, pour `Retry-After`.

## Le piège mesuré

`Limit::response()` lève une `HttpResponseException`. Le rendu générique `\Throwable` de `bootstrap/app.php` passe **avant** Laravel (`Handler:618` puis `:623`) : mesuré à travers le Handler réel, cette exception ressortait en **500 muet, `Retry-After` perdu**. Un rendu dédié la rend désormais telle quelle — le comportement documenté de Laravel.

## Écarté

- **Déduire le motif dans le rendu global du 429** : il ignore quel seau a refusé.
- **`error_code`** : exigé par le schéma `ErrorResponse` mais envoyé par aucun endpoint ; le précédent réellement lu par le front est `reason`.
- **Une énumération des motifs** (recommandée par l'audit de sécurité, pour qu'aucun appelant ne passe `reason: $e->getMessage()`) : les cinq motifs déjà émis par le dépôt sont des littéraux ; une énumération réservée aux sept nouveaux créerait une seconde convention. À reprendre si l'on type **tous** les motifs de l'API à la fois — la liste fermée existe déjà côté contrat (`RefusMotive.reason.enum`).

## Ce qui reste

- **Un contrôleur qui ne passe pas `reason:` supprime le motif sans signal.** Les deux portes d'inscription le passent ; les sept autres relais de `BusinessException` (#898) ne le font pas, et perdraient un motif qu'un service poserait demain.

## Deux erreurs de ce ticket, corrigées avant fusion

Relevées en relisant la mémoire du projet, que j'avais sautée :

- **Un point d'entrée pour `getMessage()` dans le trait.** Le premier jet ajoutait `businessErrorResponse(BusinessException)`, contraire à R4 d'une spec approuvée que je n'avais pas lue. Remplacé par le paramètre `reason` d'`errorResponse()`.
- **Un nettoyage des seaux entre tests qui existait déjà.** `Tests\TestCase::setUp()` vide Redis avant chaque test (#374). Le trait de nettoyage, et le `tearDown` que #885 avait posé sur la même prémisse fausse, sont retirés.
- **Le 429 `institution_cap_reached` ne porte pas `Retry-After`** : il vient d'un refus métier, pas d'un seau. Son motif dit déjà « jusqu'au lendemain ».

## Ce qui ferait changer d'avis

Qu'un client ait besoin du motif sur un statut qui n'a qu'un sens — il faudrait alors toujours l'émettre, et la règle d'omission tomberait.
