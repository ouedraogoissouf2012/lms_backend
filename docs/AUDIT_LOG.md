# Journal d'audit (#215)

Traçabilité « qui a fait quoi, quand, depuis où » pour la conformité SOC2/GDPR
et les investigations de sécurité.

## Ce qui est journalisé

| Source | Actions |
|---|---|
| Modèles sensibles (`Evaluation`, `EvaluationSubmission`, `QuizAttempt`) | `create`, `update`, `delete` |
| Authentification (`AuthController`) | `login`, `logout`, `login_failed` |

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
