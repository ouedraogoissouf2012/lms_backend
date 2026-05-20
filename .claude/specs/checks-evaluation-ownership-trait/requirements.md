# ChecksEvaluationOwnership trait — DRY refactor of 3 FormRequests

> Issue GitHub : [#125 [refactor] Trait ChecksEvaluationOwnership — factoriser les 3 FormRequests Eval](https://github.com/ouedraogoissouf2012/lms_backend/issues/125)
>
> Identifié par l'audit `spec-architect` (finding LOW DRY) de la PR [#122](https://github.com/ouedraogoissouf2012/lms_backend/pull/122) (#119). Promesse à honorer après la PR sécurité, explicitement écartée par `.claude/specs/klassci-enseignant-id-separation/design.md §9.4` (« mélange un fix sécurité avec un refactor DRY → diff plus difficile à auditer »).

## Contexte

Les 3 FormRequests `DeleteEvaluationRequest`, `PublishEvaluationRequest`, `UpdateEvaluationRequest` ont des `authorize()` quasi-identiques — 37 lignes × 3 = **111 lignes de duplication** :

```php
// Pattern identique dans les 3 fichiers (différences uniquement dans les
// commentaires inline « can delete/publish/modify »)
public function authorize(): bool
{
    $user = auth()->user();

    if (!$user) {
        return false;
    }

    // Coordinators cannot delete/publish/modify evaluations
    if ($user->role === 'coordinateur') {
        return false;
    }

    // Evaluation must exist and belong to user's institution
    $evaluation = \App\Models\Evaluation::where('id', $this->route('id'))
        ->where('institution_id', $user->institution_id)
        ->first();

    if (!$evaluation) {
        return false;
    }

    // Check ownership: only the assigned enseignant can modify.
    //
    // Issue #119 — lire $user->klassci_enseignant_id (colonne dédiée write-once,
    // initialisée au sign-up KLASSCI). Le blob `klassci_data['enseignant_id']`
    // est écrasable par un re-sync KLASSCI compromis et ne doit JAMAIS être lu
    // pour de l'autorisation.
    if (!$user->isAdmin()) {
        $userKlassciEnseignantId = $user->klassci_enseignant_id;
        if ($userKlassciEnseignantId === null
            || $evaluation->klassci_enseignant_id !== $userKlassciEnseignantId) {
            return false;
        }
    }

    return true;
}
```

**Conséquence** : ajouter un nouveau check (par ex. « éval pas verrouillée », « pas dans le passé », ou un nouveau champ d'autorité) demande de modifier 3 fichiers en parallèle, avec risque d'oubli/divergence.

## Solution

Extraire la logique dans un trait `App\Http\Requests\Concerns\ChecksEvaluationOwnership` que les 3 FormRequests utilisent via `use`. Pattern Laravel idiomatique (cf. `Illuminate\Foundation\Http\FormRequest` lui-même utilise des traits internes pour ses comportements).

## Requirements (EARS)

### REQ-1 — Trait `ChecksEvaluationOwnership` créé avec API minimaliste

WHERE le trait est créé,
THE classe SHALL être placée à `app/Http/Requests/Concerns/ChecksEvaluationOwnership.php` (nouveau sous-dossier `Concerns/` qui sert de convention pour les traits FormRequest futurs).

THE trait SHALL exposer une seule méthode `protected function checkEvaluationOwnership(): bool` qui retourne `true` si l'utilisateur authentifié peut accéder à l'évaluation référencée par `$this->route('id')`, `false` sinon.

THE trait SHALL implémenter exactement le pattern actuel (cf. `DeleteEvaluationRequest::authorize` pré-PR) — aucun changement de logique. Refactor pur, pas de feature.

### REQ-2 — Les 3 FormRequests utilisent le trait

WHEN les FormRequests sont modifiés,
THE `DeleteEvaluationRequest`, `PublishEvaluationRequest` et `UpdateEvaluationRequest` SHALL :

1. Ajouter `use \App\Http\Requests\Concerns\ChecksEvaluationOwnership;` au début de la classe
2. Remplacer le corps complet de `authorize()` par `return $this->checkEvaluationOwnership();`
3. Conserver leur `rules()` et `messages()` propres (logique non-partagée)

### REQ-3 — PHPDocs nettoyées

WHEN les PHPDocs des 3 FormRequests sont touchées,
THE sections suivantes SHALL être conservées (logique propre à chaque endpoint) :
- `## Purpose`
- `## 10-year perspective` ou équivalent
- Toute note spécifique au verb HTTP (`destroy`/`publish`/`update`)

THE section dupliquée `## Authorization Model` SHALL être remplacée par une référence concise vers le trait, par ex. :

```php
/**
 * ## Authorization
 * Delegated to {@see \App\Http\Requests\Concerns\ChecksEvaluationOwnership::checkEvaluationOwnership()}.
 * Identical behavior across DeleteEvaluationRequest / PublishEvaluationRequest /
 * UpdateEvaluationRequest (issue #125 refactor).
 */
```

### REQ-4 — Tests Unit du trait

WHERE le trait est testé,
THE fichier `tests/Unit/Http/Requests/Concerns/ChecksEvaluationOwnershipTest.php` SHALL être créé.

THE suite SHALL utiliser une classe FormRequest concrète de test (`TestChecksEvaluationOwnershipRequest`) déclarée à l'intérieur du fichier de test, qui `use`s le trait, afin d'exercer le pattern sans dépendre des 3 FormRequests réels.

THE suite SHALL couvrir au minimum les 8 scénarios suivants :

| # | Test | Scénario | Assertion |
|---|---|---|---|
| 1 | `test_returns_false_when_user_is_not_authenticated` | `auth()->user()` retourne `null` | `false` |
| 2 | `test_returns_false_for_coordinateur` | User authentifié `role=coordinateur` | `false` |
| 3 | `test_returns_false_when_evaluation_not_found` | `$this->route('id')` pointe sur une éval inexistante | `false` |
| 4 | `test_returns_false_for_evaluation_in_other_institution` | Éval existe mais `institution_id` ≠ user | `false` |
| 5 | `test_returns_true_for_owner_with_matching_klassci_enseignant_id` | User `role=enseignant`, `klassci_enseignant_id=42`, éval `klassci_enseignant_id=42` | `true` |
| 6 | `test_returns_true_for_admin_regardless_of_klassci_enseignant_id` | User `role=supradmin`, `klassci_enseignant_id=null`, éval `klassci_enseignant_id=999` | `true` (admin bypass) |
| 7 | `test_returns_false_for_non_admin_with_null_klassci_enseignant_id` | User `role=enseignant`, `klassci_enseignant_id=null`, éval `klassci_enseignant_id=42` | `false` |
| 8 | `test_returns_false_for_non_owner_with_mismatched_klassci_enseignant_id` | User `klassci_enseignant_id=42`, éval `klassci_enseignant_id=999` | `false` |

### REQ-5 — Aucune régression Feature

WHEN les tests Feature des suites existantes sont exécutés,
THE suites SHALL passer 100% sans modification :
- `tests/Feature/Security` (35 tests pré-existants #118/#122/#127/#128 — tous touchent les 3 FormRequests via `DeleteEvaluation` / `UpdateEvaluation` / `PublishEvaluation`)
- `tests/Feature/LMS` (50 tests)
- Toutes les autres suites Feature (Quiz, Forum, Notifications, Files)

Le critère implicite : le comportement runtime des 3 FormRequests est **strictement identique** avant/après le refactor.

### REQ-6 — Documentation du trait

WHERE le trait est créé,
THE PHPDoc de classe SHALL :
- Référencer issue #125
- Référencer les 3 FormRequests qui l'utilisent
- Référencer les invariants sécurité hérités de #119 (lecture `klassci_enseignant_id` colonne write-once) et #124 (mass-assignment fermé)
- Expliciter pourquoi le trait existe (DRY, ajout d'un check futur = 1 fichier modifié)

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|---|---|
| Éliminer la double-query `Evaluation::findOrFail($id)` côté controller (déjà chargée par le trait) | Optimisation perf, pas DRY. Scope chirurgical du refactor. Issue follow-up séparée si jugée prioritaire. |
| Étendre le pattern à d'autres FormRequests Quiz/Forum/Notification | Sémantiques d'ownership différentes (`enseignant_id` vs `etudiant_id` vs admin scope). Chaque domaine mérite son propre trait dédié. À ouvrir en issues séparées si besoin. |
| Renommer `Concerns/` en `Behaviors/` ou autre | Convention Laravel idiomatique pour les traits Eloquent et Request. Cohérent avec [`app/Models/Traits/`](app/Models/Traits/) existant — le nouveau sous-dir suit la même logique. |
| Refactor des `rules()` ou `messages()` qui pourraient aussi avoir des patterns communs | Pas de duplication identifiée — chaque endpoint a ses propres règles d'input. À ré-évaluer si un pattern émerge. |
| Composition via méthode statique (`Evaluation::checkOwnership($user, $evaluationId)`) au lieu de trait | Trait est plus idiomatique Laravel pour FormRequest. Statique sur Model viole DIP. |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ REQ-1 à REQ-6 implémentés + couverts par les 8 tests REQ-4
2. ✓ `vendor/bin/phpstan analyse` reste à `[OK] No errors`
3. ✓ `vendor/bin/phpunit tests/` passe (avec `pdo_pgsql` en CI)
4. ✓ Les 3 FormRequests ont leur `authorize()` réduit à `return $this->checkEvaluationOwnership();` (1 ligne)
5. ✓ `spec-architect` audit retourne 0 finding HIGH/CRITICAL (l'audit DRY MEDIUM identifié dans #122 doit disparaître)
6. ✓ `spec-security` audit retourne 0 finding HIGH/CRITICAL (refactor pur, pas de régression sécurité)
7. ✓ `spec-reviewer` audit retourne MERGE-READY
8. ✓ Issue #125 fermée manuellement post-merge (branche `lms` ≠ default)

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **Les checks d'autorisation des 3 FormRequests deviennent divergents** (par ex. seul `Delete` doit autoriser un admin, ou seul `Publish` doit refuser après une certaine heure). Dans ce cas, le trait devient un anti-pattern — la composition explicite est meilleure. **Réversible** : retirer l'utilisation du trait et restaurer les `authorize()` explicites.
2. **Un 4ᵉ ou 5ᵉ FormRequest Eval émerge** (par ex. `ArchiveEvaluationRequest`, `LockEvaluationRequest`). Si tous suivent le même pattern, le trait est validé par les faits. Sinon, le trait reste utile pour les 3 originaux.
3. **Le pattern d'authorize change drastiquement** (nouveau check de tenant, audit log obligatoire à chaque check, etc.). Dans ce cas, le trait est mis à jour une seule fois, ce qui est l'objectif. Pas d'invalidation.
4. **La règle « coordinateur ne peut pas modifier d'éval » est levée** (un coordinateur peut publier les évals de son département). REQ-1 doit être révisée. Mais ce serait un changement business, pas un problème de trait.

Aucune de ces 4 conditions n'est connue aujourd'hui.
