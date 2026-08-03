# Whitelist du blob `klassci_data` (cache display) — défense en profondeur contre injection KLASSCI

> Issue GitHub : [#477 \[hardening\] Restreindre `klassci_data` aux clés whitelistées avant sérialisation](https://github.com/ouedraogoissouf2012/lms_backend/issues/477)
> Épique parente : [#472](https://github.com/ouedraogoissouf2012/lms_backend/issues/472) · rattachée à [#470](https://github.com/ouedraogoissouf2012/lms_backend/issues/470)
> Sévérité : **MEDIUM** — défense en profondeur, PRODUCTION_STANDARDS §1.2. Ce n'est **pas** une faille d'autorisation active (aucun consommateur d'autorisation ne lit `klassci_data`, cf. garde grep-able), mais une réduction de la surface d'attaque d'un KLASSCI compromis.
>
> **Nature de ce document : spécification PROSPECTIVE.** Contrairement à la régularisation rétroactive de `seances-cache-hardening`, le code de whitelist **n'est pas encore écrit**. Ce `requirements.md` spécifie le comportement attendu ; les `fichier:ligne` cités renvoient au code **existant** (audité, vérifié par lecture le 2026-08-03 sur la branche `fix/477-whitelist-klassci-data` à jour depuis `lms`) que la solution devra modifier ou préserver.

## Contexte

`users.klassci_data` est un **blob de cache display informationnel** (colonne texte, cast `App\Casts\KlassciData`). Son docblock (`app/Casts/KlassciData.php:13-20`) l'énonce sans ambiguïté :

> « ⚠️ SÉCURITÉ — ce blob est un cache display informationnel, écrasé EN BLOC à chaque re-sync KLASSCI 24h. Une instance KLASSCI compromise peut y pousser n'importe quoi. NE JAMAIS lire `$user->klassci_data['XXX_id']` pour de l'AUTORISATION. »

Le blob est **écrasé en bloc** à deux endroits, chacun sérialisant un payload KLASSCI **brut et non filtré** :

1. **Sign-up / login** — `KlassciUserSynchronizer::buildCommonData()` (`app/Services/Klassci/Auth/KlassciUserSynchronizer.php:178`) :
   ```php
   'klassci_data' => json_encode(array_merge($klassciUser, ['_lms_tenant_url' => $tenantUrl])),
   ```
   = payload KLASSCI brut (`$klassciUser` = `data.user` de `auth/login`) **+** une clé LMS interne `_lms_tenant_url`.

2. **Re-sync passive 24h** — `EnsureKlassciSync::handle()` (`app/Http/Middleware/EnsureKlassciSync.php:105`) :
   ```php
   'klassci_data' => json_encode($klassciUser),
   ```
   où `$klassciUser = $klassciMe['data']['user']` (`EnsureKlassciSync.php:87-88`), c.-à-d. le payload `auth/me` d'un **KLASSCI potentiellement compromis**, écrit **tel quel**.

Dans les deux cas, **aucune restriction** n'est appliquée sur les clés : un KLASSCI compromis peut injecter dans le stockage local `is_admin`, `permissions`, `role`, ou toute structure arbitraire (imbrication profonde, clés à volume abusif). Ces clés sont ensuite **exposées au frontend** (cf. Vecteur B ci-dessous).

### Objectif

Filtrer `klassci_data` contre une **whitelist explicite** *avant* sérialisation, de sorte que le blob stocké ne contienne **que** :
- (a) les clés d'affichage réellement consommées (issues du payload KLASSCI) ;
- (b) les clés LMS internes `_lms_*`, **préservées à travers les re-syncs**.

Tout le reste est **rejeté silencieusement** (droppé, pas d'erreur — la résilience du login/re-sync prime).

## Les deux vecteurs d'injection (cartographie vérifiée)

| Vecteur | Point d'entrée | Destination | Couvert par la whitelist ? |
|---------|----------------|-------------|-----------------------------|
| **A — Stockage local** | `KlassciUserSynchronizer:178` (login) + `EnsureKlassciSync:105` (re-sync) | colonne `users.klassci_data` → relue par `KlassciConfigResolver`, `BackfillEnseignantIdCommand` | ✅ **Oui** — c'est le cœur de #477 |
| **B — Réponse `/auth/me`** | `AuthController::me()` (`app/Http/Controllers/API/AuthController.php:108-114`) | JSON frontend via `AuthResponsePresenter::profile()` (`AuthResponsePresenter.php:165`) | ✅ **Oui** — voir divergence #477 ci-dessous |

### ⚠️ Divergence issue #477 vs code réel — Vecteur B

L'issue #477 formule que la réponse `/auth/me` est polluée parce qu'elle relit le **blob stocké**. **Le code réel diffère** et il faut le corriger dans le design :

`AuthController::me()` (`AuthController.php:108-114`) **ne lit PAS** `$user->klassci_data`. Il effectue un **appel LIVE** à KLASSCI :
```php
$klassciMe = $this->klassciService->get('auth/me');
$userData  = is_array($klassciMe['data']['user'] ?? null) ? $klassciMe['data']['user'] : [];
// ...
return $this->presenter->profile($user, $userData);
```
Puis `AuthResponsePresenter::profile()` (`AuthResponsePresenter.php:155-167`) place `$userData` **directement** dans la clé `klassci_data` de la réponse — **sans passer par le stockage ni par aucun filtre**.

**Conséquence** : la whitelist appliquée au seul stockage (Vecteur A) **ne protégerait PAS** le frontend, car `/auth/me` court-circuite le cache. Le design **doit** appliquer la **même** whitelist au payload live avant de le remettre au presenter, sinon le vecteur B reste ouvert. C'est un renforcement de la portée par rapport à la lettre de l'issue, cohérent avec son intention (« ni dans le stockage local ni dans la réponse /auth/me »).

## Tableau des clés (source, décision whitelist, consommateur)

Clés observées dans les payloads KLASSCI (`data.user` de `auth/login` et `auth/me`) et dans `UserFactory` (`database/factories/UserFactory.php:40-45`), avec la décision retenue et le consommateur qui la justifie.

| Clé | Source | Whitelist ? | Consommateur / justification (vérifié) |
|-----|--------|:-----------:|----------------------------------------|
| `id` | payload KLASSCI | ✅ conserver | Identifiant KLASSCI de l'utilisateur, affiché ; présent dans `UserFactory:41`. Attention : ce n'est **pas** `enseignant_id`. |
| `nom` | payload KLASSCI | ✅ conserver | Affichage nom ; consommé par `KlassciUserSynchronizer:173` (`$klassciUser['nom']`) et `EnsureKlassciSync:103`. `UserFactory:42`. |
| `name` | payload KLASSCI | ✅ conserver | Variante anglophone de `nom` (fallback `$klassciUser['nom'] ?? $klassciUser['name']`, `KlassciUserSynchronizer:173`, `EnsureKlassciSync:103`). |
| `prenom` | payload KLASSCI | ✅ conserver | Affichage prénom ; présent dans `UserFactory:43`. Consommé côté frontend (profil). |
| `photo` | payload KLASSCI | ✅ conserver | URL avatar display. Candidat d'affichage — **à confirmer en design** (non observé dans le code back, purement frontend). |
| `role` | payload KLASSCI | ✅ conserver (**display only**) | Affichage du libellé de rôle. ⚠️ **JAMAIS pour autoriser** — l'autorisation lit `users.role` (colonne dédiée). Le garder dans le blob est inoffensif tant que la garde grep-able est maintenue (cf. REQ-6). |
| `enseignant_id` | payload KLASSCI | ✅ conserver | **Consommateur critique** : `BackfillEnseignantIdCommand` (`app/Console/Commands/Klassci/BackfillEnseignantIdCommand.php:106`, `data_get($blob, 'enseignant_id')`) — backfill scale-out de `klassci_enseignant_id`. Aussi lu par les tests d'évaluation (`PublishEvaluationRequestTest.php:34,63`, `DeleteEvaluationRequestTest.php:29`) et `UserFactory:44`. ⚠️ Lecture pour **backfill/ownership seed**, **jamais** pour autorisation runtime (docblock `User.php:22-23`, `ChecksEvaluationOwnership.php:29`). |
| `_lms_tenant_url` | **LMS interne** (ajoutée par le back, `KlassciUserSynchronizer:178`) | ✅ conserver (**clé `_lms_*`**) | `KlassciConfigResolver::resolve()` (`app/Services/Klassci/KlassciConfigResolver.php:148`, `$user->klassci_data['_lms_tenant_url']`) — résolution tenant, fallback anciens comptes pré-#75. **Doit survivre aux re-syncs** (cf. finding ci-dessous). |
| `is_admin`, `permissions`, `is_superuser`, `scopes`, … | payload KLASSCI (**injectables**) | ❌ **rejeter** | Aucun consommateur légitime. Vecteur d'escalade display / confusion frontend si un KLASSCI compromis les pousse. Cible directe du durcissement. |
| toute autre clé arbitraire | payload KLASSCI | ❌ **rejeter** | Whitelist = **liste blanche stricte** : tout ce qui n'est pas explicitement listé est droppé (pas de blacklist — une blacklist laisserait passer les clés inconnues futures). |

> La liste exacte des clés d'affichage (notamment `photo`, et l'inclusion ou non de `id` vs `enseignant_id`) est **à trancher définitivement en design** avec le frontend comme arbitre. `enseignant_id` et `_lms_tenant_url` sont **non négociables** (consommateurs back vérifiés). Le principe (liste blanche + préservation `_lms_*`) est, lui, figé par ce document.

## ⚠️ FINDING de l'audit (au-delà de la lettre de #477) — `_lms_tenant_url` perdu à la re-sync

**Confirmé par lecture du code :**

- Au **sign-up/login**, `KlassciUserSynchronizer:178` écrit `array_merge($klassciUser, ['_lms_tenant_url' => $tenantUrl])` → la clé `_lms_tenant_url` **est présente**.
- À la **re-sync 24h**, `EnsureKlassciSync:105` écrit `json_encode($klassciUser)` = payload KLASSCI **SEUL** → la clé `_lms_tenant_url` **est PERDUE** (écrasée par un blob qui ne la contient pas).

**Impact** : incohérence de données. Non fatal aujourd'hui car `KlassciConfigResolver` (`KlassciConfigResolver.php:156-158`) migre silencieusement l'URL vers la **colonne dédiée** `klassci_tenant_url` à la première résolution (`updateQuietly`), et la priorité 1 lit d'abord `$user->klassci_tenant_url` (`:142`) avant de retomber sur le blob (`:147-148`). Le fallback blob ne sert donc que les comptes jamais résolus depuis la colonne. Mais la perte reste une **incohérence silencieuse** que la whitelist doit **corriger, pas aggraver**.

**Exigence dérivée** : la whitelist ne se contente pas de *filtrer* le payload KLASSCI ; elle doit **préserver les clés `_lms_*` déjà présentes** dans le blob courant du user au moment de la re-sync (relire l'existant, ré-appliquer les `_lms_*`). C'est REQ-3 ci-dessous.

## Requirements (EARS)

### REQ-1 — Whitelist stricte (liste blanche) appliquée avant sérialisation

WHERE `klassci_data` est construit à partir d'un payload KLASSCI, que ce soit au sign-up/login (`KlassciUserSynchronizer:178`) ou à la re-sync 24h (`EnsureKlassciSync:105`),
THE système SHALL ne conserver dans le blob sérialisé **que** les clés figurant dans une whitelist explicite et centralisée, et rejeter (dropper silencieusement) toute clé hors whitelist.

WHEN le payload KLASSCI contient une clé non whitelistée (`is_admin`, `permissions`, ou toute clé arbitraire injectée par un serveur compromis),
THE système SHALL l'exclure du blob stocké — le blob résultant NE SHALL contenir **aucune** clé hors `{ whitelist d'affichage } ∪ { clés _lms_* préservées }`.

THE whitelist SHALL être une **liste blanche** (tout ce qui n'est pas listé est rejeté), **jamais** une liste noire — de sorte qu'une clé malveillante future inconnue soit rejetée par défaut.

### REQ-2 — Filtrage DRY via un collaborateur unique partagé

WHERE le filtrage doit s'appliquer aux **deux** chemins d'écriture (login `KlassciUserSynchronizer` et re-sync `EnsureKlassciSync`),
THE système SHALL exposer **une seule** implémentation du filtrage (helper / collaborateur DI unique, ou logique dans le `set()` du cast `App\Casts\KlassciData`), **injectée / réutilisée** aux deux endroits — jamais dupliquée (pattern MANIFESTE_REFACTORING §1.1 DRY, comme `SeanceCacheDataBuilder` pour les séances).

THE point d'application exact (helper partagé vs `KlassciData::set()`) SHALL être tranché en **design**, pas ici ; ce requirement fige seulement l'unicité de la source du filtrage.

IF le filtrage est placé dans le cast `KlassciData::set()`,
THEN le cast SHALL rester le **seul** point de vérité et les deux call-sites SHALL cesser d'appeler `json_encode` manuellement (ils affecteraient un array, le cast sérialisant après filtrage).

### REQ-3 — Préservation des clés LMS internes `_lms_*` à travers les re-syncs

WHERE une re-sync 24h reconstruit `klassci_data` (`EnsureKlassciSync:105`),
THE système SHALL **relire** le blob `klassci_data` courant du user et **ré-appliquer** toute clé préfixée `_lms_` (notamment `_lms_tenant_url`) au nouveau blob, de sorte qu'aucune clé `_lms_*` existante ne soit perdue par l'écrasement.

WHEN un user créé au sign-up (avec `_lms_tenant_url` présent, `KlassciUserSynchronizer:178`) subit une re-sync 24h,
THE blob résultant SHALL **toujours** contenir `_lms_tenant_url` avec sa valeur d'origine — corrigeant le finding d'incohérence documenté ci-dessus.

THE whitelist SHALL traiter le préfixe `_lms_` comme un **namespace réservé LMS** toujours autorisé (passthrough des clés `_lms_*` existantes), distinct de la whitelist d'affichage des clés KLASSCI.

### REQ-4 — Non-régression du consommateur `KlassciConfigResolver` (`_lms_tenant_url`)

WHERE `KlassciConfigResolver::resolve()` lit `$user->klassci_data['_lms_tenant_url']` en fallback (`KlassciConfigResolver.php:147-148`) pour les comptes sans `klassci_tenant_url`,
THE clé `_lms_tenant_url` SHALL rester présente et lisible dans le blob après filtrage, pour tout user pour lequel elle existait avant — la résolution tenant des anciens comptes NE SHALL pas régresser.

### REQ-5 — Non-régression du consommateur `BackfillEnseignantIdCommand` (`enseignant_id`)

WHERE `BackfillEnseignantIdCommand` lit `data_get($blob, 'enseignant_id')` depuis `klassci_data` (`BackfillEnseignantIdCommand.php:106`) pour backfiller la colonne d'autorité `klassci_enseignant_id`,
THE clé `enseignant_id` (si présente dans le payload KLASSCI) SHALL être conservée par la whitelist, de sorte que le backfill scale-out continue de fonctionner à l'identique.

WHEN un enseignant est synchronisé avec un `enseignant_id` dans son payload KLASSCI,
THE blob stocké SHALL contenir `enseignant_id`, et les tests d'ownership évaluation qui le relisent (`PublishEvaluationRequestTest`, `DeleteEvaluationRequestTest`) SHALL rester verts.

### REQ-6 — Filtrage de la réponse `/auth/me` (Vecteur B — clôture de la divergence #477)

WHERE `AuthController::me()` construit la réponse de profil à partir du payload **live** `auth/me` (`AuthController.php:108-114`, `$userData` non filtré aujourd'hui) transmis à `AuthResponsePresenter::profile()` (`AuthResponsePresenter.php:165`),
THE système SHALL appliquer la **même** whitelist (REQ-1) au payload live **avant** de le remettre au presenter, de sorte que la réponse `/auth/me` exposée au frontend ne contienne, sous la clé `klassci_data`, **que** les clés whitelistées.

WHEN un KLASSCI compromis renvoie `is_admin: true` / `permissions: [...]` dans `auth/me`,
THE réponse JSON `/auth/me` renvoyée au frontend NE SHALL contenir aucune de ces clés dans `klassci_data`.

> Ce requirement **corrige la lettre de #477** : `/auth/me` court-circuite le stockage (appel live), donc filtrer le seul Vecteur A ne suffirait pas. Cf. section « Divergence issue #477 vs code réel ».

### REQ-7 — Préservation des invariants CRITICAL-05 (aucune régression sécurité voisine)

WHERE la whitelist touche `klassci_data`,
THE système NE SHALL modifier **aucun** des invariants CRITICAL-05 verrouillés dans `EnsureKlassciSync:92-107` : `role` LMS jamais re-syncé, `email` jamais re-syncé, `klassci_enseignant_id` write-once, `klassci_role` informatif. La whitelist opère **exclusivement** sur le contenu de `klassci_data`.

WHEN la suite `tests/Feature/Security/KlassciRoleSeparationTest.php` est exécutée,
THE 4 tests (`test_initial_sync_initializes_both_roles`, `test_login_does_not_overwrite_role_when_user_exists`, `test_resync_route_does_not_grant_admin_to_etudiant_when_klassci_lies`, `test_multi_tenant_isolation_on_resync`) SHALL rester **verts** sans modification.

### REQ-8 — Conservation de la garde grep-able (documentation défensive)

WHERE le docblock du cast `App\Casts\KlassciData:13-20` et le commentaire de garde `EnsureKlassciSync:98-101` documentent « NE JAMAIS lire `klassci_data` pour de l'AUTORISATION »,
THE système SHALL **conserver** ces gardes grep-ables, et les **compléter** pour documenter la nouvelle whitelist (liste des clés autorisées, namespace `_lms_*`, point d'application unique) — de sorte qu'un futur développeur comprenne la contrainte et où l'étendre.

### REQ-9 — Résilience : filtrage tolérant aux payloads dégradés

WHEN le payload KLASSCI est vide, partiel, ou contient des types inattendus (valeur non-scalaire sous une clé whitelistée, `null`),
THE filtrage NE SHALL **jamais** lever d'exception interrompant le login (`KlassciUserSynchronizer`) ou la re-sync (`EnsureKlassciSync` — déjà en `try/catch` non-bloquant, `:111-118`) — il SHALL produire un blob valide (au pire vide + `_lms_*` préservées) et laisser le flux se poursuivre.

THE filtrage SHALL préserver le comportement de `KlassciData::get()` : un blob absent/malformé se lit toujours en `array` vide (`KlassciData.php:30-43`), jamais en erreur.

### REQ-10 — Conformité PRODUCTION_STANDARDS (invariant transversal)

WHERE le chantier est livré,
THE code SHALL satisfaire, vérifié par le guard CI :
1. Tous les fichiers touchés ≤ **300 lignes** (§1.1). État actuel mesuré (`wc -l`, 2026-08-03) : `KlassciUserSynchronizer.php` = 216, `EnsureKlassciSync.php` = 168, `KlassciData.php` = 71, `AuthResponsePresenter.php` = 226, `AuthController.php` = 277 — tous avec marge, à préserver.
2. Toutes les méthodes ≤ **40 lignes** (§5).
3. **Aucune Facade** (`DB::`, `Log::`, `Http::`, `Hash::`) introduite en code métier du nouveau collaborateur — DI strict §1.6 D. (`EnsureKlassciSync` utilise déjà la Facade `Log::` : bordure middleware pré-existante, hors périmètre de ce chantier ; ne pas en ajouter dans le helper de whitelist.)
4. **PHPStan level 9** = 0 erreur, **sans ajout** d'entrée de baseline.
5. `php artisan test` = **100 %** vert.

## Tests requis (issue #477)

| # | Scénario | Assertion attendue |
|---|----------|--------------------|
| T1 | Payload `auth/me` (re-sync) avec clés inattendues (`is_admin`, `permissions`) | Blob `klassci_data` stocké ne contient **que** clés whitelistées + `_lms_*` (REQ-1) |
| T2 | User sign-up (avec `_lms_tenant_url`) puis re-sync 24h | `_lms_tenant_url` **toujours présent** après re-sync (REQ-3, corrige le finding) |
| T3 | Payload avec `enseignant_id` | `enseignant_id` **présent** post-filtrage ; `BackfillEnseignantIdCommand` backfille correctement (REQ-5) |
| T4 | Réponse `/auth/me` avec payload live injecté (`is_admin`) | JSON frontend : `klassci_data` ne contient **aucune** clé hors whitelist (REQ-6, Vecteur B) |
| T5 | `KlassciRoleSeparationTest` (4 tests) | **Verts** sans modification (REQ-7, invariants CRITICAL-05) |
| T6 | Payload vide / malformé / types inattendus | Aucune exception ; login et re-sync aboutissent ; blob valide (REQ-9) |
| T7 | Filtrage DRY | Un **seul** point de filtrage exercé par les deux chemins (login + re-sync) — vérifié par test unitaire du helper/cast en isolation (REQ-2) |

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|------|----------------------|
| **Rendre `klassci_data` lisible pour l'autorisation** | Antithétique au docblock (`KlassciData.php:17`). L'autorisation reste sur les colonnes dédiées (`role`, `klassci_enseignant_id`). La whitelist **ne change pas** ce principe. |
| **Migration de backfill du blob existant** (re-filtrer les blobs déjà stockés) | Les blobs seront re-filtrés naturellement à la prochaine re-sync 24h (`EnsureKlassciSync`). Une migration one-shot est possible mais orthogonale — à tracer séparément si le délai de 24h est jugé inacceptable pour les données déjà polluées. |
| **Validation de schéma stricte des valeurs** (types, longueurs, format des clés whitelistées) | La whitelist filtre les **clés**, pas le contenu des valeurs. Une validation de valeur (ex. `photo` doit être une URL) est un durcissement distinct, non demandé par #477. |
| **Chiffrement / signature du blob** | Le blob est un cache display non sensible ; le vecteur est l'injection de structure, pas la confidentialité. Hors périmètre. |
| **Refactor de `EnsureKlassciSync` pour retirer la Facade `Log::`** | Dette pré-existante (bordure middleware). Le chantier n'introduit pas de nouvelle Facade mais ne nettoie pas l'existante — focus et diff auditable. |
| **Suppression de `role` du blob** | `role` reste toléré comme clé display (whitelistée) tant que la garde grep-able interdit sa lecture pour autorisation. Le retirer casserait un affichage frontend sans gain de sécurité (l'autorisation ne l'a jamais lu). |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ REQ-1 à REQ-10 satisfaits par le code livré.
2. ✓ Whitelist = **liste blanche** stricte, centralisée, **unique** (DRY), appliquée aux deux chemins d'écriture (login + re-sync).
3. ✓ Clés `_lms_*` (dont `_lms_tenant_url`) **préservées** à travers les re-syncs — finding d'incohérence **corrigé** (test T2 vert).
4. ✓ `enseignant_id` conservé — `BackfillEnseignantIdCommand` et tests d'évaluation verts (T3).
5. ✓ Réponse `/auth/me` (Vecteur B) filtrée par la **même** whitelist — divergence #477 close (T4).
6. ✓ `KlassciRoleSeparationTest` (invariants CRITICAL-05) verts sans modification (T5).
7. ✓ Filtrage résilient aux payloads dégradés — login/re-sync jamais interrompus (T6).
8. ✓ Gardes grep-ables (`KlassciData` docblock + `EnsureKlassciSync`) conservées et complétées (REQ-8).
9. ✓ Tous fichiers touchés ≤ 300 lignes ; toutes méthodes ≤ 40 lignes ; aucune Facade ajoutée dans le helper ; PHPStan level 9 = 0 erreur.
10. ✓ `php artisan test` = 100 % vert.
11. ✓ Issue #477 fermée post-merge ; divergence de portée (Vecteur B) documentée dans la PR.

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **Un consommateur d'autorisation se met à lire `klassci_data`** (violation du docblock `KlassciData.php:17`) — la whitelist display ne suffirait plus ; il faudrait promouvoir la ou les clés concernées en **colonne dédiée d'autorité** write-once (comme `klassci_enseignant_id` l'a été en #119), et non les laisser dans un blob filtrable côté KLASSCI.
2. **`klassci_data` est supprimé** au profit de colonnes structurées dédiées (nom, prénom, photo, tenant_url, enseignant_id en colonnes) — le blob et son cast disparaîtraient, la whitelist deviendrait sans objet, et la préservation `_lms_*` serait remplacée par des colonnes natives.
3. **KLASSCI expose un contrat de payload versionné et signé** (garantie d'intégrité du payload `auth/me` / `auth/login`) — l'injection de structure arbitraire deviendrait impossible en amont, réduisant la whitelist à une simple projection d'affichage, plus une défense en profondeur.
4. **`/auth/me` cesse d'appeler KLASSCI en live** et relit le blob stocké (`AuthController::me` re-câblé) — le Vecteur B (REQ-6) se replierait sur le Vecteur A ; le double filtrage deviendrait redondant et il faudrait retirer le filtrage live pour éviter la duplication.
5. **Le namespace `_lms_*` est abandonné** au profit d'un stockage des métadonnées LMS hors blob — REQ-3 (préservation `_lms_*`) perdrait sa raison d'être.

Aucune de ces 5 conditions n'est connue aujourd'hui.
