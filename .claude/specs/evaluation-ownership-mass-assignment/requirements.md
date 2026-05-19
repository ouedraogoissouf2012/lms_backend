# Evaluation ownership mass-assignment — Server-side enforcement

> Issue GitHub : [#124 [security MEDIUM] EvaluationController — mass-assignment klassci_enseignant_id permet transfert d'ownership](https://github.com/ouedraogoissouf2012/lms_backend/issues/124)
>
> Identifié par l'audit `spec-security` (finding F1 MEDIUM) de la PR [#122](https://github.com/ouedraogoissouf2012/lms_backend/pull/122) (#119). Adjacent à #119 (qui a fixé la **lecture** d'ownership) mais pas l'**écriture**.

## Contexte

L'issue #119 (PR #122) a fermé le vecteur IDOR sur la *lecture* de `klassci_enseignant_id` dans 3 FormRequests Eval (Delete/Publish/Update). La protection s'appuie sur la colonne write-once `users.klassci_enseignant_id` (jamais réécrite par re-sync KLASSCI).

Mais **un vecteur adjacent subsiste sur l'écriture** dans `EvaluationController` :

```php
// app/Http/Controllers/API/EvaluationController.php:171-195 (store)
$evaluation = Evaluation::create(array_merge(
    $request->only([
        // ...
        'klassci_enseignant_id',   // ← VIENT DU CLIENT
        // ...
    ]),
    [...]
));
```

```php
// app/Http/Controllers/API/EvaluationController.php:272 (update)
$evaluation->update($request->except(['questions']));   // ← TOUS les champs body sauf questions
```

Avec ces deux ouvertures, un enseignant authentifié peut :

### Scénario 1 — Pollution d'inbox au CREATE

Enseignant A authentifié POST `/api/evaluations` avec body :
```json
{
  "klassci_matiere_id": 5,
  "klassci_classe_id": 10,
  "klassci_enseignant_id": <id_de_B>,
  "titre": "Faux examen",
  ...
}
```

L'éval est créée avec `klassci_enseignant_id = B`. Elle apparaît dans la liste des évals de B (qui consomme `where('klassci_enseignant_id', $user->klassci_enseignant_id)`). B la voit, peut être trompé en la publiant, ou simplement avoir son inbox pollué par du contenu de A.

### Scénario 2 — Transfert d'ownership au UPDATE

1. Enseignant A possède éval E (`klassci_enseignant_id = A`)
2. A appelle `PUT /api/evaluations/{E}` avec body `{"titre": "...", "klassci_enseignant_id": <B>}`
3. `UpdateEvaluationRequest::authorize()` voit `eval.klassci_enseignant_id = A == user.klassci_enseignant_id = A` → autorisé
4. Le controller exécute `$evaluation->update($request->except(['questions']))` — `klassci_enseignant_id` n'étant pas exclu, devient `B`
5. Désormais, E appartient à B :
   - A ne peut plus modifier E (sa nouvelle `authorize()` retourne false)
   - L'éval E reste avec son contenu (créé par A) mais sous l'identité de B

L'impact pratique de ce scénario 2 est limité (A se tire dans le pied) mais c'est un anti-pattern à fermer (data integrity, audit trail, et défense en profondeur si un autre check d'autorisation devient strict plus tard).

Les deux scénarios sont **silencieux** (aucun log spécifique), **sans audit trail**, et **bypass complet** du modèle d'ownership write-once posé par #119.

## Solution

Pattern « server-side enforcement » :
1. **CREATE** : ne plus lire `klassci_enseignant_id` du body. Forcer côté serveur depuis `$user->klassci_enseignant_id`.
2. **UPDATE** : exclure explicitement `klassci_enseignant_id` (et autres champs immuables : `institution_id`, `klassci_classe_id`, `klassci_matiere_id`, `klassci_evaluation_id`) de la liste des champs writeable.
3. **FormRequest** : retirer la règle `klassci_enseignant_id` du `StoreEvaluationRequest` (le client n'a pas à le fournir).

## Requirements (EARS)

### REQ-1 — CREATE : `klassci_enseignant_id` dérivée du token uniquement

WHEN un utilisateur appelle `POST /api/evaluations`,
THE controller SHALL utiliser `$user->klassci_enseignant_id` (où `$user = $this->authenticatedUser($request)`) comme valeur unique pour `klassci_enseignant_id`.

THE controller SHALL ne PAS lire ni `$request->klassci_enseignant_id`, ni `$request->only(['klassci_enseignant_id', ...])`.

IF `$user->klassci_enseignant_id === null` (user LMS local ou compte service sans identité enseignant KLASSCI),
THE controller SHALL retourner `403 Forbidden` avec un message clair : « Vous devez être un enseignant KLASSCI synchronisé pour créer une évaluation. ».

Justification : un user sans `klassci_enseignant_id` ne peut pas être l'enseignant d'une éval — le check d'ownership de #119 échouera de toute façon, autant échouer tôt et clairement au create.

### REQ-2 — UPDATE : `klassci_enseignant_id` immuable + autres champs d'identité

WHEN un utilisateur appelle `PUT /api/evaluations/{id}`,
THE controller SHALL exclure les champs suivants de la liste des champs mis à jour :
- `klassci_enseignant_id` (ownership write-once REQ-1)
- `institution_id` (isolation tenant)
- `klassci_classe_id` (cible de l'éval — immuable post-create)
- `klassci_matiere_id` (matière de l'éval — immuable post-create)
- `klassci_evaluation_id` (référence KLASSCI — immuable post-create)
- `questions` (déjà exclu, géré séparément)

THE controller SHALL utiliser `$request->except(['questions', 'klassci_enseignant_id', 'institution_id', 'klassci_classe_id', 'klassci_matiere_id', 'klassci_evaluation_id'])` ou équivalent.

IF le body contient ces champs, THE controller SHALL ignorer silencieusement leurs valeurs (backward-compat — anciens clients ne reçoivent pas d'erreur).

### REQ-3 — `StoreEvaluationRequest` : retrait de la règle obsolète

WHEN `StoreEvaluationRequest::rules()` est modifié,
THE classe SHALL retirer la règle `'klassci_enseignant_id' => 'nullable|integer'`. Le champ n'a plus à être validé en input car il n'est plus lu du body.

### REQ-4 — `Evaluation` model `$fillable` : préservé

THE colonne `klassci_enseignant_id` SHALL rester dans `Evaluation::$fillable` malgré la dette technique connue (mass-assignment théorique). Justification :
- Les `UserFactory`, `EvaluationFactory`, seeders et tests existants dépendent de la mass-assignment via factory states
- La retirer du `$fillable` casserait `Evaluation::factory()->create(['klassci_enseignant_id' => 42])` utilisé par les 50+ tests Feature
- La protection se fait au niveau **controller** (CREATE force, UPDATE exclut), pas au niveau model — c'est le pattern Laravel idiomatique pour ce cas

### REQ-5 — Tests obligatoires

WHEN les tests sont écrits,
THE suite SHALL couvrir au minimum :

| # | Test | Description | Assertion clé |
|---|---|---|---|
| 1 | `test_create_evaluation_forces_klassci_enseignant_id_from_token` | Enseignant A (`klassci_enseignant_id=42`) POST body forge `<999>` | éval créée avec `klassci_enseignant_id === 42` |
| 2 | `test_create_evaluation_blocked_for_user_without_klassci_enseignant_id` | User authentifié avec `klassci_enseignant_id = null` POST | 403 |
| 3 | `test_create_evaluation_ignores_klassci_enseignant_id_from_body_silently` | Enseignant A POST sans le champ + POST avec le champ → résultat identique | `klassci_enseignant_id === A->klassci_enseignant_id` dans les 2 cas |
| 4 | `test_update_evaluation_cannot_transfer_ownership` | Enseignant A possède E (`klassci_enseignant_id=A`). PUT body forge `<B>` | Response 200 OK, `evaluation->fresh()->klassci_enseignant_id === A` (pas B) |
| 5 | `test_update_evaluation_cannot_change_institution_id` | A PUT body `{"institution_id": <autre>}` | `institution_id` inchangé |
| 6 | `test_update_evaluation_cannot_change_klassci_classe_id` | A PUT body `{"klassci_classe_id": <autre>}` | `klassci_classe_id` inchangé |
| 7 | `test_update_evaluation_can_still_change_titre` | A PUT body `{"titre": "Nouveau titre"}` | `titre` mis à jour (régression check : on n'a pas cassé l'update légitime) |

### REQ-6 — Aucune régression sur les workflows légitimes

WHEN les suites Feature existantes (`tests/Feature/LMS`, `tests/Feature/Security`, `tests/Feature/Forum`, `tests/Feature/Quiz`, `tests/Feature/Notifications`, `tests/Feature/Files`) sont exécutées,
THE suite SHALL passer 100% sans modification. Aucun consommateur de `Evaluation::create()` ou `Evaluation::update()` ne doit être altéré (factories + seeders dépendent toujours du mass-assignment legit).

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|---|---|
| Retirer `klassci_enseignant_id` du `$fillable` de `Evaluation` | Casse les factories/seeders existants. Solution `forceFill` côté code est intrusive. La protection au niveau controller suffit. À ré-évaluer si un autre site de mass-assignment émerge. |
| Refactor de `institution_id` mass-assignment ailleurs dans l'app | Audit séparé : `BelongsToInstitution` trait gère déjà l'isolation tenant globale ; la non-modifiabilité au PUT est un sous-problème spécifique. Ouvrir une issue dédiée si besoin. |
| Auditer/réparer rétroactivement les évaluations dont l'ownership a été transféré par exploitation passée | Aucun cas connu, base actuelle ne permet pas de distinguer un transfert légitime (n'existe pas — pas de UI admin pour ça) d'un transfert malveillant. Mitigation ops si soupçon. |
| Ajouter un audit log structuré quand un body forge `klassci_enseignant_id` (« attempted_ownership_transfer ») | Bruit potentiel si des anciens clients envoient le champ par habitude. Le silencieux ignore est plus tolérant. Si un volume anormal de tentatives est suspecté, ajouter le log dans une PR de suivi avec metrics. |
| Refactor du store/update en service `EvaluationService` (SRP) | Dette pré-existante du god-controller `EvaluationController` 1640 lignes — déjà identifié spec-architect MEDIUM hors scope #122. À traiter dans le grand refacto futur. |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ REQ-1 à REQ-6 implémentés + couverts par les tests REQ-5
2. ✓ `vendor/bin/phpstan analyse` reste à `[OK] No errors`
3. ✓ `vendor/bin/phpunit tests/` passe (avec `pdo_pgsql` en CI)
4. ✓ `spec-security` audit retourne 0 finding HIGH/CRITICAL
5. ✓ `spec-architect` audit retourne 0 finding HIGH/CRITICAL
6. ✓ `spec-reviewer` audit retourne MERGE-READY
7. ✓ Issue #124 fermée manuellement post-merge (branche `lms` ≠ default)

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **Cas légitime : admin LMS crée une évaluation au nom d'un enseignant donné** (ex : enseignant absent, admin doit publier le sujet en urgence). Dans ce cas, REQ-1 doit être révisée : autoriser `$request->klassci_enseignant_id` **uniquement si** `$user->isAdmin()`, avec audit log `evaluation_created_for_other_teacher`. Aucun besoin métier identifié aujourd'hui.
2. **Workflow de réassignation d'ownership** est introduit (ex : un prof part de l'école, ses évaluations sont réassignées). Dans ce cas, REQ-2 doit être révisée : ajouter une route admin dédiée `PATCH /admin/evaluations/{id}/reassign-owner` avec `EnsureRole admin` et audit log. Aucun workflow connu aujourd'hui.
3. **La colonne `klassci_enseignant_id` change de sémantique** (passage à multi-ownership, table de liaison). REQ-1/REQ-2 globalement à reconcevoir.

Aucune de ces 3 conditions n'est connue aujourd'hui.
