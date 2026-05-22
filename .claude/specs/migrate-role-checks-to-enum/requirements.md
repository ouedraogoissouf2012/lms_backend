# Migrate role checks to enum — PR #121b (follow-up de #121)

> Issue GitHub : [#132 [refactor] Migrer les 77 sites disséminés in_array(\$user->role, …) / === 'X' vers User::isXxx() ou App\Enums\Role](https://github.com/ouedraogoissouf2012/lms_backend/issues/132)
>
> Follow-up de [#121](https://github.com/ouedraogoissouf2012/lms_backend/issues/121) (mergé via [PR #130](https://github.com/ouedraogoissouf2012/lms_backend/pull/130)). PR #121a a créé `App\Enums\Role` + migré les 2 sites racine (User helpers + EnsureKlassciSync). Cette PR finit le travail en migrant les sites disséminés.

## Contexte

Post-#121a, l'enum `App\Enums\Role` est la source de vérité unique pour les rôles utilisateurs LMS. Mais **85 sites précis** dans **~40 fichiers** continuent à hardcoder les strings :

- **49** `in_array($user->role, [...])` avec listes de rôles
- **36** comparaisons strictes `$user->role === 'X'` / `!== 'X'`

Ces sites continuent à fonctionner (le comportement runtime est préservé), mais :
1. **Type-safety perdu** : un typo comme `'etudient'` ne casse pas à la compile
2. **Maintenance double** : si un nouveau rôle est introduit (`directeur`), il faut grep manuel pour s'assurer que tous les sites le reconnaissent
3. **Bug latent d'alias** : certains checks `=== 'enseignant'` ne matchaient pas l'alias `'teacher'` historiquement stocké en DB → comportement inconsistant entre sites
4. **Lisibilité** : `in_array($user->role, ['etudiant', 'student'])` mélange détail d'implémentation et intention (`isStudent()` est plus expressif)

## Solution

Migration mécanique de **tous les sites disséminés** vers les helpers `User::isXxx()` ou l'enum `App\Enums\Role`. **Une seule PR** mécanique (refactor pur, comportement runtime préservé), avec **un fix mineur** sur l'enum pour supporter un alias historique (`'étudiant'` avec accent) découvert pendant la discovery.

## Requirements (EARS)

### REQ-1 — Enum `Role` étendu pour supporter l'alias `'étudiant'` (avec accent)

WHERE l'enum `App\Enums\Role` est modifié,
THE méthode `tryFromString` SHALL accepter la valeur `'étudiant'` (avec accent aigu sur le 'é') et la normaliser vers `Role::Etudiant`.

Justification : 2 sites pré-existants listent explicitement cet alias (`SearchController.php:74,103` et `LMSSeancesController.php:457`). Le comportement runtime original les supporte. Si on migre vers `$user->isStudent()` sans étendre l'enum, ces 2 sites cessent de matcher `'étudiant'` — régression potentielle si la DB contient cette valeur. La modification est minime (1 ligne ajoutée au `match` + 1 test Unit) et défensive.

### REQ-2 — Migration des `in_array` simple (1 catégorie de rôle)

WHEN un site contient `in_array($user->role, ['etudiant', 'student'])` ou variantes équivalentes,
THE remplacement SHALL utiliser `$user->isStudent()` (helper User).

THE remplacement SHALL appliquer le mapping suivant :

| Pattern original | Remplacement |
|---|---|
| `in_array($x->role, ['etudiant', 'student'])` (et variantes avec étudiant) | `$x->isStudent()` |
| `in_array($x->role, ['enseignant', 'teacher'])` | `$x->isTeacher()` |
| `in_array($x->role, ['coordinateur', 'coordinator'])` | `$x->isCoordinator()` |
| `in_array($x->role, ['admin', 'administrateur', 'superAdmin', 'supradmin'])` (les 4 variants admin) | `$x->isAdmin()` |
| `in_array($x->role, ['admin', 'administrateur', 'superAdmin'])` (3 admin sans `supradmin`) | `$x->isAdmin()` — **bug fix** : élargit à l'alias `supradmin` qui était oublié, cohérent avec l'enum |

### REQ-3 — Migration des `in_array` mixtes (multi-catégories)

WHEN un site contient un `in_array` qui mélange plusieurs catégories (ex : `['coordinateur', 'superAdmin']`, `['enseignant', 'coordinateur', 'superAdmin']`),
THE remplacement SHALL utiliser une combinaison d'helpers `User::isXxx()` :

| Pattern original | Remplacement |
|---|---|
| `in_array(...role, ['coordinateur', 'superAdmin'])` (admin élargi sans `admin` standard) | `$x->isCoordinator() \|\| $x->isAdmin()` |
| `in_array(...role, ['coordinateur', 'superAdmin', 'admin'])` | `$x->isCoordinator() \|\| $x->isAdmin()` (identique car `isAdmin` couvre `admin` ET `superAdmin`) |
| `in_array(...role, ['coordinateur', 'superAdmin', 'supradmin'])` | idem |
| `in_array(...role, ['enseignant', 'teacher', 'coordinateur'])` | `$x->isTeacher() \|\| $x->isCoordinator()` |
| `in_array(...role, ['enseignant', 'teacher', 'coordinateur', 'superAdmin'])` | `$x->isTeacher() \|\| $x->isCoordinator() \|\| $x->isAdmin()` |
| `in_array(...role, ['enseignant', 'coordinateur', 'superAdmin', 'admin'])` | `$x->isTeacher() \|\| $x->isCoordinator() \|\| $x->isAdmin()` |
| `in_array(...role, ['teacher', 'enseignant', 'coordinateur', 'admin', 'superAdmin'])` | `$x->isTeacher() \|\| $x->isCoordinator() \|\| $x->isAdmin()` |

### REQ-4 — Migration des `in_array` admin-only (`['superAdmin']` ou `['supradmin']` solo)

WHEN un site contient `in_array(...role, ['superAdmin'])` ou `['supradmin']` ou `['superAdmin', 'supradmin']`,
THE remplacement SHALL utiliser `$x->asRoleEnum() === Role::Supradmin`.

Justification : `User::isAdmin()` couvre les 4 variants admin élargis (`admin`, `administrateur`, `superAdmin`, `supradmin`). Si l'intention originale était de restreindre à **uniquement** le supradmin (cas typique des FormRequests admin globaux : `BulkImportUsersRequest`, `DeleteUserRequest`, `ResetPasswordRequest`, etc.), on doit cibler explicitement `Role::Supradmin`.

### REQ-5 — Migration des comparaisons strictes `=== 'X'` / `!== 'X'`

WHEN un site contient une comparaison stricte sur un rôle,
THE remplacement SHALL utiliser :

| Pattern original | Remplacement |
|---|---|
| `$x->role === 'enseignant'` (seul, sans alias) | `$x->isTeacher()` — **bug fix** : élargit à `'teacher'` |
| `$x->role === 'etudiant'` (seul) | `$x->isStudent()` — bug fix similaire |
| `$x->role === 'coordinateur'` (seul) | `$x->isCoordinator()` |
| `$x->role === 'supradmin'` (seul) | `$x->asRoleEnum() === Role::Supradmin` |
| `$x->role === 'superAdmin'` (seul) | `$x->asRoleEnum() === Role::Supradmin` (équivalent normalisé) |
| `$x->role === 'étudiant' \|\| $x->role === 'student'` (alias FR accent + EN) | `$x->isStudent()` — couvre les 3 cas grâce à REQ-1 |
| `$x->role !== 'enseignant' && $x->role !== 'teacher'` | `!$x->isTeacher()` |
| `$x->role !== 'etudiant' && $x->role !== 'student'` | `!$x->isStudent()` |

### REQ-6 — Audit grep final exhaustif

WHEN la migration est terminée,
THE codebase SHALL satisfaire les invariants suivants (vérifiés par grep) :

- `grep -rn "in_array.*->role.*\['" app/` → **0 hit** (sauf commentaires de garde ou docs)
- `grep -rn "->role ===" app/` → **0 hit** (sauf commentaires)
- `grep -rn "->role !==" app/` → **0 hit** (sauf commentaires)

Les seules occurrences acceptables de `$user->role` post-PR :
1. Lecture pour log ou response (ex : `'role' => $user->role` dans une JSON response)
2. Le helper `User::asRoleEnum()` lui-même (1 site dans le model)

### REQ-7 — Aucune régression Feature/Unit

WHEN les suites pré-existantes sont exécutées,
THE suite SHALL passer 100% sans modification :

- `tests/Unit/Enums/RoleTest.php` (9 tests #121a + 1 nouveau pour `'étudiant'`)
- `tests/Unit/Middleware/EnsureKlassciSyncTest.php` (10 tests #118)
- `tests/Feature/Security/*` (28 tests #118/#122/#127/#128)
- `tests/Feature/Models/UserRoleHelpersTest.php` (4 tests #121a)
- `tests/Feature/LMS/*` (50 tests)
- Toutes les autres suites Feature

### REQ-8 — Test Unit additionnel pour le nouvel alias `'étudiant'`

WHEN l'enum est étendu (REQ-1),
THE fichier `tests/Unit/Enums/RoleTest.php` SHALL être étendu pour couvrir l'alias `'étudiant'` (avec accent) :

```php
public function test_try_from_string_accepts_accented_etudiant_alias(): void
{
    self::assertSame(Role::Etudiant, Role::tryFromString('étudiant'));
}
```

(Le test peut être ajouté dans le test existant `test_try_from_string_normalizes_en_aliases` ou créé séparément. Préférence : test dédié pour la traçabilité du fix.)

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|---|---|
| Refactor des routes `role:enseignant,coordinateur,...` middleware syntax | Strings de config Laravel — `EnsureRole` middleware parse ces strings. Refactor demanderait de modifier le middleware aussi, élargit le scope sans gain proportionnel. À ouvrir en issue séparée si besoin. |
| Migration de la colonne DB `users.role` vers ENUM type PostgreSQL natif | Migration data invasive, downtime, hors scope refactor applicatif. À évaluer dans une PR ops dédiée. |
| Ajout d'une méthode `Role::isTeacherOrCoordinator()` ou helpers composés | YAGNI — les helpers `User::isXxx() || $user->isYyy()` restent lisibles. Si un pattern apparaît >3 fois, considérer l'extraction. |
| Refactor du `EnsureRole` middleware pour utiliser l'enum | Le middleware parse les strings de routes (`'role:enseignant,coordinateur'`) et doit rester compatible avec cette syntaxe Laravel. Refactor interne possible mais hors scope chirurgical. |
| Suppression de `getKlassciDataAttribute` accentué (à vérifier si problème similaire ailleurs) | Hors scope (sujet différent). |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ REQ-1 à REQ-8 implémentés
2. ✓ `vendor/bin/phpstan analyse` reste à `[OK] No errors`
3. ✓ `vendor/bin/phpunit tests/` passe (avec `pdo_pgsql` en CI)
4. ✓ Audit grep REQ-6 satisfait (0 site disséminé restant)
5. ✓ `spec-security` audit retourne 0 finding HIGH/CRITICAL (refactor pur, ne doit pas régresser sécurité)
6. ✓ `spec-architect` audit retourne 0 finding HIGH/CRITICAL (le finding DRY de #121a doit être complètement éliminé)
7. ✓ `spec-reviewer` audit retourne MERGE-READY
8. ✓ Issue #132 fermée manuellement post-merge (branche `lms` ≠ default)

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **Un test Feature régresse** lors de la migration d'un site spécifique → indique que le pattern de remplacement n'est pas runtime-équivalent au site. Revenir au cas par cas, possiblement préserver le check original avec commentaire `// Intentional: enum mapping insufficient here`.
2. **Un alias inconnu de l'enum est découvert** lors du grep (ex : `'profesor'`, `'parent'`) → étendre l'enum AVANT de migrer le site.
3. **La DB contient des valeurs de rôle non listées par les alias** (à vérifier au moment de l'implémentation si possible avec `SELECT DISTINCT role FROM users`). Si oui, étendre l'enum ou laisser le check original avec justification.
4. **Le pattern de remplacement crée une régression sémantique subtile** (par ex. élargir un check à un alias qui ne devrait PAS être supporté pour un endpoint admin). Auditer cas par cas, préserver le comportement strict si demandé.

Aucune de ces 4 conditions n'est anticipée aujourd'hui mais à vérifier pendant l'implémentation.
