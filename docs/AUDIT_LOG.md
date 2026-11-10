# Journal d'audit (#215)

Traçabilité « qui a fait quoi, quand, depuis où » pour la conformité SOC2/GDPR
et les investigations de sécurité.

## Ce qui est journalisé

| Source | Actions |
|---|---|
| Modèles sensibles (`Evaluation`, `EvaluationSubmission`, `QuizAttempt`) | `create`, `update`, `delete` |
| Authentification (`LoginOrchestrator`, `AuthController`) | `login`, `logout`, `login_failed` |
| Enregistrements visio (`SeanceRecordingControlService`) | `visio_recording_start`, `visio_recording_stop`, `visio_recording_read` |
| Écoles (`InstitutionCrudService`) | `institution.mode_changed`, `institution.soft_deleted` |
| Comptes (`UserDeletionService`) | `user.soft_deleted` |
| Rétention (`RetentionRunner`) | `recording.purged`, `institution.purged`, `user.purged`, `chapter.purged`, `audit-logs.purged.bulk` |

La colonne `action` est une chaîne libre (`string(32)`) : cette table est la liste des valeurs écrites aujourd'hui.

L'école (`institution_id`) d'une entrée est celle de l'utilisateur authentifié au moment de l'écriture : les connexions
et les actions du supradmin n'en ont pas.

Chaque entrée capture : acteur (`user_id`), tenant (`institution_id`), action,
cible (`auditable_type` + `auditable_id`), diff (`before` / `after`),
provenance (`ip_address`, `user_agent`) et horodatage (`created_at`).

## Architecture

- **`App\Services\Audit\AuditLogger`** — point d'écriture unique (DI strict :
  `AuthFactory`, `Request`, `ConfigRepository` injectés). Capture acteur +
  provenance + tenant automatiquement.
- **`App\Observers\AuditableObserver`** — journalise create/update/delete.
  Branché via le trait, hors du modèle (§5). Exclut les attributs `hidden`
  (mots de passe, tokens) des diffs.
- **`App\Models\Concerns\Auditable`** — trait marqueur (sans logique) qui
  attache l'observer. `use Auditable;` dans le modèle sensible.
- **`App\Models\AuditLog`** — append-only (`UPDATED_AT = null`).

## Consultation

`GET /api/admin/audit-log` — **supradmin strict uniquement** (cross-tenant).

Filtres : `action`, `user_id`, `per_page` (1-100), `page`. Trié du plus
récent au plus ancien. Lecture seule — aucune route d'écriture/suppression.
Le filtre `action` n'accepte que les neuf valeurs historiques
(`ViewAuditLogRequest`).

Chaque entrée porte son auteur (`user: {id, name, email, role}`) et son école
(`institution: {id, name}`, D127), chargés en une requête chacun, sans scope de
tenant. Supprimés logiquement, ils restent servis ; `user` et `institution`
valent `null` sans auteur ou sans école, ou après une purge physique. Le contrat complet est dans `docs/openapi.yaml`
(`AuditLogPage`, `AuditLogEntry`).

```
GET /api/admin/audit-log?action=login&per_page=50
GET /api/admin/audit-log?user_id=42
```

> ⚠️ L'autorisation utilise la comparaison stricte `role === 'supradmin'`
> (et NON `asRoleEnum()`, qui normaliserait `superAdmin` en `Supradmin` et
> exposerait les logs cross-tenant à un admin d'institution).

## Rétention

Configurable via `config/audit.php` → `AUDIT_RETENTION_DAYS` (défaut 365 j,
**minimum 90 j** pour SOC2/GDPR).

La commande `audit:purge` (planifiée quotidiennement à 03:30) est le **seul**
mécanisme de suppression :

```bash
php artisan audit:purge            # purge réelle
php artisan audit:purge --dry-run  # compte sans supprimer
```

## Désactivation (tests de perf uniquement)

`AUDIT_ENABLED=false` court-circuite l'écriture. À ne JAMAIS positionner en
production — la traçabilité est un invariant de conformité.
