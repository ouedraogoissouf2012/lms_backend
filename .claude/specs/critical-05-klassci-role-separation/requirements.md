# CRITICAL-05 — Empêcher l'escalade de privilèges via KLASSCI sync

> Issue GitHub : [#34 CRITICAL-05: EnsureKlassciSync — Empêcher escalade privilèges](https://github.com/ouedraogoissouf2012/lms_backend/issues/34) — OPEN depuis 2026-05-15.

## Contexte

Le middleware [`app/Http/Middleware/EnsureKlassciSync.php`](../../app/Http/Middleware/EnsureKlassciSync.php) re-synchronise tous les utilisateurs authentifiés toutes les 24h en appelant `auth/me` sur l'API KLASSCI. La ligne 62 écrase la colonne `role` LMS avec la valeur reçue de KLASSCI :

```php
$user->update([
    'name'              => $klassciUser['nom'] ?? $klassciUser['name'] ?? $user->name,
    'email'             => $klassciUser['email'] ?? $user->email,
    'role'              => $klassciUser['role'] ?? $user->role,  // 🔴 ESCALADE POSSIBLE
    'klassci_data'      => json_encode($klassciUser),
    'last_klassci_sync' => now(),
]);
```

Le rôle LMS détermine toutes les autorisations applicatives :
- `User::isAdmin()` retourne `true` pour `['admin', 'administrateur', 'superAdmin', 'supradmin']`
- Le middleware `EnsureRole` consomme `$user->role` pour gater l'accès aux routes
- Des dizaines de controllers font `if ($user->role === 'enseignant')` / `'etudiant'` / `'coordinateur'`

### Scénario d'attaque

Un attaquant qui contrôle un serveur KLASSCI (compromission externe, ou tenant KLASSCI auto-hébergé par le client sans contrôle de gouvernance LMS) peut faire renvoyer par `auth/me` :

```json
{ "data": { "user": { "role": "supradmin", "email": "victime@school.fr", ... } } }
```

Lors du prochain passage du middleware (au plus 24h après le compromis), l'utilisateur LMS gagne silencieusement `role:supradmin`, ce qui lui ouvre tous les endpoints admin de la plateforme (gestion users, paramètres globaux, données cross-institution, etc.).

L'attaque est :
- **Silencieuse** : aucune action utilisateur côté LMS n'est requise. Le middleware s'exécute automatiquement.
- **Persistante** : la colonne `role` reste écrasée tant qu'un admin LMS ne corrige pas manuellement.
- **Auditable a posteriori uniquement** : aucun log actuellement ne signale le changement de rôle.

## Solution prescrite par la roadmap

> [`REFACTORING_ROADMAP.md`](../../REFACTORING_ROADMAP.md) §TIER 0 / CRITICAL-05 :
> « Ne jamais écraser role, ajouter `klassci_role` séparé. »

## Requirements (EARS)

### REQ-1 — Source de vérité unique pour autorisation

La colonne `users.role` SHALL être la **source de vérité unique** pour toutes les décisions d'autorisation applicatives.

WHEN un consommateur (middleware, controller, policy, service) prend une décision d'autorisation,
THE système SHALL lire `$user->role` et SHALL ignorer toute autre source (KLASSCI payload, `klassci_role`, headers).

### REQ-2 — Nouvelle colonne `klassci_role`

WHERE la table `users` est modifiée,
THE migration SHALL ajouter une colonne `klassci_role` de type `string(50)` nullable, indexée, placée après `role`.

WHEN un utilisateur existant est migré,
THE migration SHALL backfill `klassci_role = role` pour tous les utilisateurs ayant `klassci_id` non null, afin de garantir une baseline cohérente sans re-sync forcé.

WHEN la migration est exécutée en down,
THE migration SHALL drop la colonne `klassci_role` et son index proprement.

### REQ-3 — Sign-up initial (autorisation d'écriture unique)

WHEN un nouvel utilisateur est créé via `AuthController::syncUserFromKlassci()` (1ère connexion KLASSCI),
THE système SHALL initialiser `role` LMS *et* `klassci_role` avec la valeur fournie par KLASSCI (`$klassciUser['role'] ?? 'etudiant'`).

IF l'utilisateur existe déjà dans la DB (clé `(klassci_id, institution_id)` ou fallback `(email, institution_id)`),
THE système SHALL mettre à jour `klassci_role` avec la valeur fournie par KLASSCI mais SHALL préserver la valeur courante de `role` LMS (ne pas écraser).

Justification : il faut bien initialiser le rôle d'un nouvel utilisateur quelque part ; KLASSCI est l'autorité au moment de la création. Au-delà, le contrôle de `role` LMS appartient exclusivement à l'administration LMS.

### REQ-4 — Re-sync 24h (intouchable)

WHEN le middleware `EnsureKlassciSync` re-synchronise un utilisateur (toutes les ≥ 24h via `User::isKlassciDataFresh()`),
THE middleware SHALL mettre à jour uniquement les champs suivants :
- `name` (informatif, pas d'impact sécurité)
- `klassci_role` (nouvelle colonne)
- `klassci_data` (snapshot JSON brut)
- `last_klassci_sync` (timestamp)

THE middleware SHALL ne PAS mettre à jour les champs suivants depuis le payload KLASSCI :
- `role` LMS (autorisation — REQ-1)
- `email` (peut servir à des checks d'autorisation futurs ; principe de précaution)

### REQ-5 — Détection de divergence

WHEN le middleware `EnsureKlassciSync` reçoit un payload où `$klassciUser['role'] !== $user->role` (rôle KLASSCI différent du rôle LMS courant),
THE middleware SHALL écrire un `Log::warning` avec niveau `warning` contenant au minimum :
- `'event' => 'klassci_role_divergence_detected'`
- `'user_id'`, `'institution_id'`
- `'lms_role'` (valeur courante de `users.role`)
- `'klassci_role_received'` (valeur courante du payload KLASSCI)
- `'klassci_role_previous'` (valeur précédente de `users.klassci_role` si différente — pour distinguer un changement persistant d'un flux normal)

THE middleware SHALL ne PAS bloquer la requête ni revoquer la session.

Justification : la divergence peut être légitime (l'administration LMS a promu un utilisateur, donc `role` LMS > `klassci_role`). Logger sans bloquer permet de détecter a posteriori les tentatives d'escalade malveillantes via SOC/SIEM/Sentry sans casser les workflows légitimes.

### REQ-6 — Test multi-tenant et happy/edge path

WHEN les tests sont écrits,
THE suite SHALL couvrir au minimum :

| Test | Description | Assertion clé |
|---|---|---|
| `test_initial_sync_initializes_both_roles` | Nouveau user sign-up KLASSCI renvoie `role=etudiant` | `role === 'etudiant'` ET `klassci_role === 'etudiant'` |
| `test_initial_sync_preserves_lms_role_when_user_exists` | User existant avec `role=enseignant` se reconnecte, KLASSCI renvoie `role=etudiant` | `role === 'enseignant'` ET `klassci_role === 'etudiant'` |
| `test_resync_does_not_overwrite_role` | User existant `role=etudiant`, re-sync 24h, KLASSCI renvoie `role=supradmin` | `role === 'etudiant'` (PAS escaladé) |
| `test_resync_updates_klassci_role` | Idem précédent | `klassci_role === 'supradmin'` (info conservée) |
| `test_resync_does_not_overwrite_email` | User existant `email=foo@x.fr`, KLASSCI renvoie `email=attacker@evil.com` | `email === 'foo@x.fr'` |
| `test_resync_updates_name_and_klassci_data` | Champs informatifs MAJ | `name` et `klassci_data` mis à jour, `last_klassci_sync` = now |
| `test_resync_logs_warning_on_role_divergence` | KLASSCI renvoie un rôle différent du `role` LMS | Log `warning` avec event `klassci_role_divergence_detected` |
| `test_resync_does_not_log_when_roles_match` | KLASSCI renvoie le même rôle | Aucun log warning émis (évite la pollution) |
| `test_multi_tenant_isolation` | Re-sync user institution A → user institution B inchangé | `User::find(B)->role` inchangé |
| `test_klassci_api_failure_does_not_overwrite_role` | API KLASSCI 500 sur `auth/me` | `role` et `klassci_role` inchangés |

### REQ-7 — Aucune régression sur les check d'autorisation existants

WHEN les tests Feature des routes protégées par `role:xxx` middleware sont exécutés (`tests/Feature/LMS/**/*RoutingTest.php`, etc.),
THE suite SHALL passer 100% sans modification (aucun consommateur de `$user->role` ne doit être altéré).

## Hors scope (volontairement)

Les éléments suivants sont **explicitement exclus** de cette PR pour rester focalisé sur la racine du problème :

| Item | Pourquoi hors scope |
|---|---|
| Re-auth/relogin forcé en cas de divergence | Trop intrusif pour les utilisateurs légitimes (admin LMS qui promeut un user déclencherait un re-login partout). À reconsidérer si la fréquence des warnings de divergence dépasse un seuil opérationnel. |
| Colonne `klassci_email` séparée | Email ne sert actuellement à aucun check d'autorisation (les checks vont sur `role` ou `institution_id`). Ne pas écraser au re-sync suffit. À ré-évaluer si un endpoint commence à autoriser par email. |
| Webhook KLASSCI → LMS pour notifier un changement de rôle | Architecture push hors scope sécurité. Devrait passer par une spec dédiée (intégration KLASSCI bidirectionnelle). |
| Bloquer le retour de KLASSCI si `role` reçu est inattendu (whitelist `etudiant/enseignant/coordinateur`) | Out of scope : la liste des rôles peut évoluer côté KLASSCI sans coordination. La protection est faite côté autorisation (REQ-1), pas côté ingestion. |
| Migration de `klassci_role` vers une enum DB | YAGNI : pas de contrainte forte. Une `string(50)` nullable est suffisante et plus tolérante aux évolutions KLASSCI. |
| Auditer/réparer rétroactivement les rôles déjà escaladés par exploitation passée | Aucun cas connu, et la base actuelle ne permet pas de distinguer une promotion légitime d'une escalade. Hors scope ; mitigation côté ops (audit DB manuel si soupçon). |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ Tous les REQ-1 à REQ-7 sont implémentés et couverts par les tests listés en REQ-6
2. ✓ `vendor/bin/phpstan analyse` reste à `[OK] No errors`
3. ✓ `vendor/bin/phpunit tests/` passe 100% (avec `pdo_pgsql` en CI)
4. ✓ `php artisan migrate:fresh --seed` et `php artisan migrate:rollback` fonctionnent sans erreur
5. ✓ `spec-security` audit retourne 0 finding HIGH/CRITICAL
6. ✓ `spec-architect` audit retourne 0 finding HIGH/CRITICAL
7. ✓ `spec-reviewer` audit retourne MERGE-READY
8. ✓ L'issue GitHub #34 sera close au merge via mention `closes #34` dans le commit

## Critère d'invalidation (Q15)

Cette solution est **à invalider et reconcevoir** SI l'une des hypothèses suivantes tombe :

1. **L'administration LMS doit pouvoir déléguer les rôles à KLASSCI** (architecturalement, KLASSCI redevient l'autorité unique). Dans ce cas, la solution proposée bloque les workflows légitimes et il faut une autre approche (signature cryptographique KLASSCI, registre d'admins autorisés à pousser des changements de rôle).
2. **Le payload KLASSCI commence à inclure plusieurs rôles par tenant** (`['enseignant', 'coordinateur']`) au lieu d'un rôle scalaire. Le mapping `klassci_role` string(50) devient inadapté et il faut une table de liaison `user_klassci_roles`.
3. **Un audit légal/RGPD impose que `email` LMS reste strictement synchronisé avec KLASSCI** (pour cohérence des notifications, mailings). Dans ce cas, REQ-4 doit être revu pour distinguer `email` (sync acceptable) de `role` (jamais sync).

Aucun de ces 3 cas n'est connu aujourd'hui. La solution tient.
