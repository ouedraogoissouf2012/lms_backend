# Backfill command artisan — Préparation scale > 500k users

> Issue GitHub : [#126 [refactor] Extraire backfill klassci_enseignant_id en command artisan (préparation scale > 500k)](https://github.com/ouedraogoissouf2012/lms_backend/issues/126)
>
> Identifié dans `.claude/specs/klassci-enseignant-id-separation/design.md §10` (PR #122) comme critère scale : « À très grande échelle (>500k users), splitter le backfill en command artisan séparée. Pas un risque court terme. »
> Audit `spec-architect` MEDIUM-2 de PR #122 a confirmé ce besoin.

## Contexte

Deux migrations exécutent un backfill inline lors de leur `up()` :

### Migration 1 — `2026_05_18_000001_add_klassci_role_to_users_table.php` (PR #118 / CRITICAL-05)

```php
// Backfill: copy `role` into `klassci_role` for KLASSCI-synced users.
DB::table('users')
    ->whereNotNull('klassci_id')
    ->orderBy('id')
    ->chunkById(1000, function ($users) {
        DB::table('users')
            ->whereIn('id', $users->pluck('id'))
            ->update(['klassci_role' => DB::raw('role')]);
    });
```

Backfill simple, 1 UPDATE par chunk. Performance OK même à 200k.

### Migration 2 — `2026_05_19_000001_add_klassci_enseignant_id_to_users_table.php` (PR #122 / #119)

```php
// Backfill: extract `enseignant_id` from klassci_data JSON blob.
DB::table('users')
    ->whereNotNull('klassci_id')
    ->whereNull('klassci_enseignant_id')
    ->orderBy('id')
    ->chunkById(1000, function ($users) {
        foreach ($users as $u) {
            $blob = is_string($u->klassci_data) ? json_decode($u->klassci_data, true) : (array) $u->klassci_data;
            $enseignantId = data_get($blob, 'enseignant_id');
            if (is_numeric($enseignantId)) {
                DB::table('users')->where('id', $u->id)->update(['klassci_enseignant_id' => (int) $enseignantId]);
            }
        }
    });
```

Backfill PHP-side (foreach + json_decode + 1 UPDATE par user). Plus coûteux. **Scale issue à >500k** : timeout possible sur le déploiement orchestré (Forge, GitHub Actions deploy, etc.).

## Problème à résoudre

À >500k users, les backfills inline dans `migrate` risquent :
1. **Timeout du runner de déploiement** (typique 5-10 min sur Forge/GH Actions)
2. **Lock long sur la table `users`** pendant la migration (UPDATE chunks)
3. **Impossible de monitorer ou interrompre** (la migration est tout ou rien)
4. **Impossible de rejouer** le backfill seul après migration (devrait re-écrire la migration ou faire `migrate:rollback` + `migrate`)

## Solution

Extraire chaque backfill dans un **command artisan dédié** :
- `php artisan klassci:backfill-role`
- `php artisan klassci:backfill-enseignant-id`

Les commands sont :
- **Idempotents** (filtre `whereNotNull('klassci_id')->whereNull('klassci_X')`)
- **Configurables** : `--chunk=1000` ajustable, `--dry-run` pour audit
- **Observables** : progress bar + comptage avant/après + logs
- **Indépendants** des migrations : peuvent être rejoués à tout moment

**Stratégie de non-régression** : on **NE retire PAS** le backfill inline des migrations existantes. Justification :
- Migrations déjà tournées sur les environnements actuels (dev, staging, prod éventuelle) → retirer le backfill ne ferait rien rétroactivement
- Les futures migrations sur de nouveaux environnements (CI, nouveaux tenants) bénéficient du backfill inline automatique
- Les commands artisan sont un **outil ops supplémentaire** pour scale, pas un remplacement
- Garde la migration **autosuffisante** (1 commande = 1 état complet)

## Requirements (EARS)

### REQ-1 — Command `klassci:backfill-role` créé

WHERE le command est créé,
THE classe SHALL être placée à `app/Console/Commands/Klassci/BackfillRoleCommand.php`.

THE signature SHALL être :
```
klassci:backfill-role {--chunk=1000 : Number of users per chunk}
                     {--dry-run : Show what would be updated without writing}
```

THE command SHALL :
1. Compter `users` avec `klassci_id IS NOT NULL` AND `klassci_role IS NULL` (filtre idempotence)
2. Afficher le total avant traitement
3. Itérer via `chunkById($chunk)` sur les users matchant le filtre
4. Pour chaque chunk : `UPDATE users SET klassci_role = role WHERE id IN (...)`
5. Progress bar mise à jour à chaque chunk
6. Compte final affiché : nombre de rows modifiées
7. Si `--dry-run` : afficher le COUNT mais ne PAS exécuter les UPDATE
8. Retourner `Command::SUCCESS` (0) en cas de succès, `Command::FAILURE` (1) en cas d'erreur

### REQ-2 — Command `klassci:backfill-enseignant-id` créé

WHERE le command est créé,
THE classe SHALL être placée à `app/Console/Commands/Klassci/BackfillEnseignantIdCommand.php`.

THE signature SHALL être identique à REQ-1 (`--chunk`, `--dry-run`).

THE command SHALL :
1. Compter `users` avec `klassci_id IS NOT NULL` AND `klassci_enseignant_id IS NULL` (filtre idempotence)
2. Itérer via `chunkById($chunk)` sur les users matchant le filtre
3. Pour chaque user : extraire `klassci_data` (string → json_decode → array), lire `enseignant_id`
4. Si `enseignant_id` est numérique : `UPDATE users SET klassci_enseignant_id = $enseignantId WHERE id = $user->id`
5. Si `enseignant_id` absent ou non-numérique : skip silencieux (compté dans le total "skipped")
6. Progress bar
7. Compte final : nombre de rows modifiées + nombre de rows skipped (raison : pas d'enseignant_id dans le blob)
8. Si `--dry-run` : afficher les compteurs sans exécuter les UPDATE
9. Retourner `Command::SUCCESS` (0) en cas de succès, `Command::FAILURE` (1) en cas d'erreur

### REQ-3 — Migrations existantes : commentaires ops ajoutés

WHEN les 2 migrations existantes sont modifiées (et SEULEMENT le PHPDoc),
THE migration SHALL ajouter un commentaire au-dessus de la section backfill mentionnant :

```
// NOTE: Pour les déploiements à grande échelle (>500k users) où ce backfill
// inline risque un timeout, utiliser plutôt le command artisan dédié :
//   php artisan klassci:backfill-{role|enseignant-id} --chunk=2000
// Le command est IDEMPOTENT (filtre `whereNull('klassci_X_id')`) — peut être
// rejoué autant de fois que nécessaire sans effet de bord.
```

**Aucun changement de logique** dans les migrations (refactor pur documentation).

### REQ-4 — Tests Feature pour les 2 commands

WHEN les commands sont testés,
THE fichiers `tests/Feature/Console/BackfillRoleCommandTest.php` + `BackfillEnseignantIdCommandTest.php` SHALL être créés.

THE suite `BackfillRoleCommandTest` SHALL couvrir au minimum :

| # | Test | Scénario | Assertion clé |
|---|---|---|---|
| 1 | `test_backfill_copies_role_to_klassci_role_for_synced_users` | 3 users avec `klassci_id` non null + `klassci_role = null` | Tous ont `klassci_role = role` après exec |
| 2 | `test_backfill_skips_users_without_klassci_id` | User avec `klassci_id = null` (compte supradmin local) | `klassci_role` reste `null` |
| 3 | `test_backfill_is_idempotent` | Run 2 fois → 2ᵉ run reporte 0 row à backfiller | OK + counter 0 |
| 4 | `test_dry_run_does_not_write_to_db` | Avec `--dry-run` | `klassci_role` reste `null` mais counter affiché |
| 5 | `test_returns_success_exit_code_on_normal_run` | Run normal | Exit code 0 |

THE suite `BackfillEnseignantIdCommandTest` SHALL couvrir au minimum :

| # | Test | Scénario | Assertion clé |
|---|---|---|---|
| 1 | `test_backfill_extracts_enseignant_id_from_blob` | User avec `klassci_data['enseignant_id'] = 42` | `klassci_enseignant_id = 42` |
| 2 | `test_backfill_skips_users_without_enseignant_id_in_blob` | User étudiant (`klassci_data` sans `enseignant_id`) | `klassci_enseignant_id` reste `null` |
| 3 | `test_backfill_is_idempotent` | Run 2 fois | OK + counter 0 |
| 4 | `test_dry_run_does_not_write_to_db` | `--dry-run` | DB inchangée |
| 5 | `test_handles_malformed_klassci_data_gracefully` | User avec `klassci_data = 'invalid json'` ou `klassci_data = null` | Pas de crash, user skipped |
| 6 | `test_returns_success_exit_code_on_normal_run` | Run normal | Exit code 0 |

### REQ-5 — Aucune régression sur migrations existantes

WHEN les tests existants (Feature/Security PR #118/#122/#127/#128 + Feature Models + Backfill migration tests) sont exécutés,
THE suite SHALL passer 100% sans modification — les migrations préservent leur comportement runtime exact.

### REQ-6 — Documentation des commands

WHERE les commands sont créés,
THE PHPDoc de classe SHALL :
- Référencer issue #126 + la migration source
- Documenter l'invariant idempotence (filtre `whereNull(...)`)
- Documenter le scale-out attendu (chunk configurable pour >500k)
- Mentionner le mode `--dry-run` pour audit

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|---|---|
| Retirer le backfill inline des 2 migrations | Risque de régression sur environnements déjà migrés (le backfill inline est idempotent par construction) ; les commands sont un outil ops supplémentaire, pas un remplacement |
| Un command universel `klassci:backfill {column}` paramétré | YAGNI à 2 occurrences. Les 2 backfills ont des logiques propres (SQL simple vs JSON decode). |
| Backfill rétroactif pour `EvaluationSubmission.klassci_etudiant_id` ou autres colonnes | Aucun besoin identifié — ces colonnes sont write-once à la création et n'ont jamais nécessité de backfill |
| Job Laravel queue (`dispatch(BackfillRoleJob::class)`) au lieu d'un command artisan | YAGNI — le command exécuté en CLI suffit pour ops manuel. Si async devient nécessaire, ajouter `Bus::dispatch($command)` est trivial |
| Backfill multi-tenant scopé par `institution_id` | Les colonnes `klassci_role` et `klassci_enseignant_id` sont stockées sur la table `users` qui a déjà la colonne `institution_id`. Un backfill global itère tous les users — l'isolation tenant est préservée par construction. |
| Migration vers un PostgreSQL ENUM natif pour `klassci_role` | Hors scope ops. À évaluer dans une PR DBA séparée. |
| Backfill batch via SQL pur (sans PHP `foreach`) pour `klassci_enseignant_id` | Bonne idée mais nécessite des extensions JSON path SQL (PG `->>'enseignant_id'` ou MySQL `JSON_EXTRACT`). Casse la portabilité SQLite/MySQL/PG. Le foreach PHP reste portable. |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ REQ-1 à REQ-6 implémentés
2. ✓ `vendor/bin/phpstan analyse` reste à `[OK] No errors`
3. ✓ `vendor/bin/phpunit tests/` passe (avec `pdo_pgsql` en CI)
4. ✓ `php artisan list klassci` affiche les 2 nouveaux commands
5. ✓ `php artisan klassci:backfill-role --dry-run` s'exécute sans erreur en local
6. ✓ `php artisan klassci:backfill-enseignant-id --dry-run` idem
7. ✓ `spec-security` audit retourne 0 finding HIGH/CRITICAL
8. ✓ `spec-architect` audit retourne 0 finding HIGH/CRITICAL
9. ✓ `spec-reviewer` audit retourne MERGE-READY
10. ✓ Issue #126 fermée manuellement post-merge

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **Le volume de users dépasse 5M** et les commands deviennent eux-mêmes trop lents → migrer vers une approche batch parallèle (Bus::batch avec workers Horizon) ou vers SQL pur avec JSON path (perdre portabilité).
2. **Un 3ᵉ ou 4ᵉ backfill émerge** → considérer extraction d'un trait/base class commun. Pas un anti-pattern aujourd'hui à 2 commands (YAGNI).
3. **La table `users` est dénormalisée** (par exemple split en `users_klassci` séparée) → adapter les filtres `whereNotNull('klassci_id')` au nouveau schéma.
4. **Les colonnes `klassci_role`/`klassci_enseignant_id` deviennent calculées on-the-fly** (computed column ou accessor Eloquent) → les commands de backfill deviennent inutiles, à supprimer.

Aucune de ces 4 conditions n'est connue aujourd'hui.
