# Role enum — PHP 8.1 backed string + alias EN/FR + helpers

> Issue GitHub : [#121 [refactor] Centraliser les rôles dans App\Enums\Role (PHP 8.1)](https://github.com/ouedraogoissouf2012/lms_backend/issues/121)
>
> Identifié par l'audit `spec-architect` (finding MEDIUM DRY) de la PR [#118](https://github.com/ouedraogoissouf2012/lms_backend/pull/118) (#34 / CRITICAL-05). Le finding LOW spec-security de PR [#129](https://github.com/ouedraogoissouf2012/lms_backend/pull/129) (#125) a confirmé que le refactor centralise et **amplifie** le couplage par chaîne pré-existant — d'où l'urgence relative.

## Contexte

Les rôles utilisateurs LMS sont définis et hiérarchisés à 3 endroits différents dans le codebase :

### Site 1 — `app/Models/User.php` (méthodes `isXxx()`)

```php
public function isTeacher(): bool {
    return $this->role === 'enseignant' || $this->role === 'teacher';
}
public function isCoordinator(): bool {
    return $this->role === 'coordinateur' || $this->role === 'coordinator';
}
public function isStudent(): bool {
    return $this->role === 'etudiant' || $this->role === 'student';
}
public function isAdmin(): bool {
    return in_array($this->role, ['admin', 'administrateur', 'superAdmin', 'supradmin']);
}
```

4 méthodes avec listes de chaînes hardcodées dupliquant les rôles.

### Site 2 — `app/Http/Middleware/EnsureKlassciSync.php` (const `ROLE_PERMISSIVITY`, PR #118)

```php
private const ROLE_PERMISSIVITY = [
    'etudiant'       => 1,
    'student'        => 1,
    'enseignant'     => 2,
    'teacher'        => 2,
    'coordinateur'   => 3,
    'coordinator'    => 3,
    'admin'          => 4,
    'administrateur' => 4,
    'superAdmin'     => 5,
    'supradmin'      => 5,
];
```

10 entrées qui dupliquent les mêmes alias EN/FR + hiérarchie.

### Site 3 — 77 sites disséminés dans `app/Http/Controllers/` et FormRequests

- **51** `in_array($user->role, [...])` avec rôles hardcodés
- **26** comparaisons strictes `$user->role === 'X'` / `!== 'X'`

Conséquence : ajouter un nouveau rôle (`directeur`, `parent`) demande de modifier 3+ sites indépendants, avec risque élevé d'oubli/divergence (par ex. le middleware reconnaît `directeur` mais `User::isAdmin()` ne le sait pas → ACL incohérente).

## Solution

Pattern enum PHP 8.1 backed-string, factory `tryFromString` qui normalise les alias EN/FR. Refactor en **2 PRs** :

- **PR #121a (cette PR)** : créer l'enum + helpers + migrer les 2 sites de couplage **racine** (`User::isXxx()` méthodes + `EnsureKlassciSync::ROLE_PERMISSIVITY`). 100% du bénéfice DRY est capté (1 source de vérité = l'enum), mais les 77 sites disséminés restent inchangés — ils continuent à fonctionner via les méthodes `isXxx()` du model (maintenant propulsées par l'enum).

- **PR #121b (issue follow-up à créer post-merge)** : migrer les 77 sites `in_array(...)` / `=== 'X'` vers les helpers `isXxx()` ou directement vers l'enum. Pur cleanup, aucun risque sécurité.

Justification du découpage : la PR #121a est **chirurgicale** (~5 fichiers, ~150 LOC) et **runtime-équivalente** (les méthodes `isXxx()` continuent à retourner les mêmes booléens). Tous les tests Feature pré-existants restent verts. Une PR follow-up peut migrer les 77 sites sans risque.

## Requirements (EARS)

### REQ-1 — Enum `App\Enums\Role` créé

WHERE l'enum est créé,
THE classe SHALL être placée à `app/Enums/Role.php` (nouveau sous-dossier `Enums/`).

THE enum SHALL être un **backed enum** de type `string` (PHP 8.1) avec exactement les 5 cases suivantes :
- `Etudiant     = 'etudiant'`
- `Enseignant   = 'enseignant'`
- `Coordinateur = 'coordinateur'`
- `Admin        = 'admin'`
- `Supradmin    = 'supradmin'`

THE valeurs canoniques SHALL être en **français** (format prédominant dans les données réelles côté KLASSCI).

### REQ-2 — `Role::tryFromString(?string): ?self` accepte les alias EN/FR

WHEN un consommateur lit `$user->role` depuis la DB (qui peut historiquement contenir des alias EN comme `student`, `teacher` ou des variantes `administrateur`, `superAdmin`),
THE méthode `Role::tryFromString(?string $value): ?self` SHALL convertir les alias suivants vers leur case canonique :

| Input | Sortie |
|---|---|
| `'etudiant'`, `'student'` | `Role::Etudiant` |
| `'enseignant'`, `'teacher'` | `Role::Enseignant` |
| `'coordinateur'`, `'coordinator'` | `Role::Coordinateur` |
| `'admin'`, `'administrateur'` | `Role::Admin` |
| `'supradmin'`, `'superAdmin'` | `Role::Supradmin` |
| `null` ou toute autre valeur | `null` |

THE méthode SHALL être **insensible à la casse exacte** uniquement pour les alias documentés ci-dessus (pas de `strtolower`, juste un mapping littéral pour rester explicite).

### REQ-3 — Méthodes de l'enum

THE enum SHALL exposer les méthodes suivantes :

- **`permissivity(): int`** — retourne la hiérarchie 1-5 (`Etudiant=1`, `Enseignant=2`, `Coordinateur=3`, `Admin=4`, `Supradmin=5`)
- **`isAdmin(): bool`** — retourne `true` pour `Admin` et `Supradmin`, `false` sinon
- **`isMorePermissiveThan(Role $other): bool`** — retourne `$this->permissivity() > $other->permissivity()`

THE enum SHALL ne PAS exposer de méthode `isTeacher/isStudent/isCoordinator` directement — ces helpers restent sur le model `User` (pour minimiser la surface API et garder la cohérence avec `User::isAdmin()` qui sera la 4ᵉ méthode).

### REQ-4 — `User` model migration

WHERE le model `User` est modifié,
THE classe SHALL :

1. Ajouter une méthode `public function asRoleEnum(): ?Role` qui retourne `Role::tryFromString($this->role)`
2. Refactoriser `isTeacher()` : `return $this->asRoleEnum() === Role::Enseignant;`
3. Refactoriser `isCoordinator()` : `return $this->asRoleEnum() === Role::Coordinateur;`
4. Refactoriser `isStudent()` : `return $this->asRoleEnum() === Role::Etudiant;`
5. Refactoriser `isAdmin()` : `return $this->asRoleEnum()?->isAdmin() ?? false;`
6. Importer `App\Enums\Role` en haut de fichier

THE comportement runtime des 4 méthodes `isXxx()` SHALL être **strictement identique** avant/après le refactor (validé par les tests existants).

### REQ-5 — `EnsureKlassciSync` migration

WHERE le middleware `EnsureKlassciSync` est modifié,
THE classe SHALL :

1. **Retirer entièrement** la constante `private const ROLE_PERMISSIVITY = [...]` (10 entrées)
2. Refactoriser `isEscalationAttempt(?string $lmsRole, ?string $klassciRole): bool` pour utiliser `Role::tryFromString` :
   ```php
   $lmsLevel     = Role::tryFromString($lmsRole)?->permissivity() ?? 0;
   $klassciLevel = Role::tryFromString($klassciRole)?->permissivity() ?? 0;
   return $klassciLevel > $lmsLevel;
   ```
3. Importer `App\Enums\Role` en haut de fichier

THE comportement runtime du log `klassci_role_divergence_detected` SHALL être strictement identique (validé par les tests Unit pré-existants `EnsureKlassciSyncTest`).

### REQ-6 — Tests Unit de l'enum

WHERE l'enum est testé,
THE fichier `tests/Unit/Enums/RoleTest.php` SHALL être créé.

THE suite SHALL utiliser `PHPUnit\Framework\TestCase` (pas `Tests\TestCase`) car l'enum est une pure value object sans dépendance DB/HTTP.

THE suite SHALL couvrir au minimum les 10 scénarios suivants :

| # | Test | Scénario | Assertion |
|---|---|---|---|
| 1 | `test_cases_have_expected_canonical_values` | Toutes les 5 cases ont les valeurs FR attendues | 5 `assertSame` |
| 2 | `test_try_from_string_returns_canonical_for_fr_input` | `tryFromString('etudiant'/'enseignant'/...)` | 5 returns canoniques |
| 3 | `test_try_from_string_normalizes_en_aliases` | `tryFromString('student')`, `'teacher'`, `'coordinator'` | Returns FR canoniques |
| 4 | `test_try_from_string_normalizes_admin_aliases` | `tryFromString('administrateur')` → `Admin`, `tryFromString('superAdmin')` → `Supradmin` | Returns Admin/Supradmin |
| 5 | `test_try_from_string_returns_null_for_invalid` | `tryFromString('hacker')`, `null`, `''` | `null` |
| 6 | `test_permissivity_returns_expected_levels` | `Etudiant->permissivity() === 1`, ..., `Supradmin->permissivity() === 5` | 5 `assertSame` |
| 7 | `test_is_admin_returns_true_for_admin_and_supradmin_only` | `Admin->isAdmin()`, `Supradmin->isAdmin()` `true` ; les 3 autres `false` | 5 `assertSame` |
| 8 | `test_is_more_permissive_than` | `Supradmin->isMorePermissiveThan(Etudiant)` `true` ; `Etudiant->isMorePermissiveThan(Supradmin)` `false` | 4 cas |
| 9 | `test_is_more_permissive_than_same_role_returns_false` | `Etudiant->isMorePermissiveThan(Etudiant)` | `false` (strict, pas `>=`) |
| 10 | `test_user_is_admin_helper_delegates_to_enum` | User avec `role='supradmin'`, `role='administrateur'`, `role='etudiant'`, `role='inexistant'` | 4 cas conformes (Feature test, dans une suite séparée) |

Note : le test #10 est un **test Feature de régression** sur le model `User`, à placer dans `tests/Feature/Models/UserRoleHelpersTest.php`, séparé des tests Unit purs de l'enum.

### REQ-7 — Aucune régression Feature/Unit

WHEN les suites pré-existantes sont exécutées,
THE suite SHALL passer 100% sans modification :

- `tests/Unit/Middleware/EnsureKlassciSyncTest.php` (10 tests #118) — preuve que le refactor du middleware n'a pas changé le comportement
- `tests/Feature/Security/*` (28 tests cumulés #118/#122/#127/#128) — preuve que `User::isAdmin/isTeacher/etc()` continue à retourner les mêmes booléens
- `tests/Feature/LMS/*` (50 tests) — régression LMS générale
- Toutes les autres suites Feature

### REQ-8 — Documentation de l'enum

WHERE l'enum est créé,
THE PHPDoc de classe SHALL :

- Référencer issue #121 (refactor)
- Documenter les alias EN/FR acceptés par `tryFromString`
- Documenter pourquoi `User::isXxx()` reste sur le model (séparation API surface enum / model)
- Pointer vers la PR follow-up #121b qui migrera les 77 sites disséminés

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|---|---|
| Migration des 77 sites `in_array(...)` / `=== 'X'` dans les controllers | PR #121b — pure cleanup, decoupable, aucun risque sécurité. Permet de garder #121a chirurgicale. |
| Migration de la colonne DB `users.role` vers PostgreSQL ENUM type | Risqué (downtime, data migration). Pas besoin pour le bénéfice DRY de l'enum applicatif. À considérer dans une PR ops dédiée. |
| Refactor des routes `role:enseignant,coordinateur,...` middleware syntax | Strings de config Laravel — la syntaxe `EnsureRole` middleware parse déjà ces strings et la modification ferait perdre la cohérence avec le pattern Laravel idiomatique. À évaluer séparément si besoin. |
| Ajouter `Role::all()`, `Role::names()` ou autres helpers de listing | YAGNI — pas de besoin métier actuel. PHP 8.1 fournit `Role::cases()` natif si jamais. |
| Cast Eloquent automatique `'role' => Role::class` sur `User` | Casserait la rétrocompatibilité (stockage DB) parce que la colonne contient des alias EN qui ne correspondent pas aux cases canoniques. Le helper `User::asRoleEnum()` est plus flexible. À ré-évaluer post-migration de la DB. |
| Marquer les méthodes `User::isXxx()` `@deprecated` au profit de `$user->asRoleEnum() === Role::X` | Pas de deprecation utile : `isXxx()` reste l'API idiomatique pour des checks ponctuels. L'enum est complémentaire, pas concurrente. |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ REQ-1 à REQ-8 implémentés + couverts par les tests REQ-6
2. ✓ `vendor/bin/phpstan analyse` reste à `[OK] No errors`
3. ✓ `vendor/bin/phpunit tests/` passe (avec `pdo_pgsql` en CI)
4. ✓ `spec-security` audit retourne 0 finding HIGH/CRITICAL (refactor pur, pas de régression sécurité)
5. ✓ `spec-architect` audit retourne 0 finding HIGH/CRITICAL (le finding MEDIUM DRY identifié dans PR #118 doit disparaître)
6. ✓ `spec-reviewer` audit retourne MERGE-READY
7. ✓ Issue #121 fermée manuellement post-merge (branche `lms` ≠ default)
8. ✓ Issue follow-up #121b créée pour la migration des 77 sites disséminés

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **Un nouveau rôle émerge qui ne correspond à aucun des 5 canoniques** (`directeur`, `parent`, `secretaire`). Dans ce cas, l'enum doit être étendu — c'est exactement le bénéfice ciblé (1 fichier à modifier). **Pas d'invalidation**, juste l'évolution prévue.
2. **Les alias EN sont supprimés de la DB** (migration data unifiant tout en FR). Dans ce cas, `tryFromString` peut être simplifié vers `from` natif PHP. Évolution naturelle.
3. **Le pattern de hiérarchie change** (par ex. `Coordinateur` devient plus permissif que `Admin` pour son département). REQ-3 `permissivity` à reconcevoir, mais l'enum reste utile.
4. **La colonne DB `users.role` est migrée vers un type ENUM natif PostgreSQL** avec contrainte stricte. Dans ce cas, `tryFromString` devient `Role::from()` natif (les alias EN ayant disparu de la DB). Évolution naturelle.

Aucune de ces 4 conditions n'est connue aujourd'hui.
