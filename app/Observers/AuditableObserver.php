<?php

declare(strict_types=1);

namespace App\Observers;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer transversal qui journalise CREATE/UPDATE/DELETE des modèles
 * marqués `Auditable` (#215). Branché via le trait, jamais dans le modèle —
 * la logique d'audit reste hors du domaine (§5).
 *
 * Les attributs `hidden` du modèle (password, tokens…) sont exclus des diffs
 * before/after pour ne jamais journaliser de secret.
 *
 * DI strict : AuditLogger injecté par le container.
 */
final class AuditableObserver
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function created(Model $model): void
    {
        $this->auditLogger->logModelEvent('create', $model, null, $this->safeAttributes($model));
    }

    public function updated(Model $model): void
    {
        // before = valeurs d'origine des seuls attributs modifiés ; after = nouvelles.
        $changed = array_keys($model->getChanges());
        $hidden = $model->getHidden();

        $before = [];
        $after = [];
        foreach ($changed as $key) {
            if (in_array($key, $hidden, true)) {
                continue;
            }
            $before[$key] = $model->getOriginal($key);
            $after[$key] = $model->getAttribute($key);
        }

        // Rien de significatif (ex. seul un champ hidden a changé) → pas de log.
        if ($after === []) {
            return;
        }

        $this->auditLogger->logModelEvent('update', $model, $before, $after);
    }

    public function deleted(Model $model): void
    {
        $this->auditLogger->logModelEvent('delete', $model, $this->safeAttributes($model), null);
    }

    /**
     * Attributs du modèle privés des champs `hidden` (secrets).
     *
     * @return array<string, mixed>
     */
    private function safeAttributes(Model $model): array
    {
        return array_diff_key($model->getAttributes(), array_flip($model->getHidden()));
    }
}
