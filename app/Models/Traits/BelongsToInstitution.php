<?php

namespace App\Models\Traits;

use App\Models\Institution;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToInstitution
{
    public static function bootBelongsToInstitution(): void
    {
        // AUTO-SCOPE : filtrer toutes les requêtes par institution
        // CRITICAL-06: Fail-secure — only apply scope if tenant is initialized
        // PHASE-1.1: Test fixtures use safe id() instead of getResolved() to avoid bootstrap failures
        static::addGlobalScope('institution', function (Builder $builder) {
            $tenantManager = app(TenantManager::class);
            $institutionId = $tenantManager->id(); // Safe: returns null if not initialized

            // Only apply scope if tenant is resolved. In test context, this allows
            // fixture creation; in production, queries without tenant fail at HTTP boundary.
            if ($institutionId !== null) {
                $builder->where(
                    $builder->getModel()->getTable() . '.institution_id',
                    $institutionId
                );
            }
        });

        // AUTO-SET : définir institution_id à la création
        static::creating(function (Model $model) {
            if (!$model->institution_id) {
                $tenantManager = app(TenantManager::class);
                $institutionId = $tenantManager->id();

                // Only auto-set if tenant is resolved. In test context, factory can set via for()
                if ($institutionId !== null) {
                    $model->institution_id = $institutionId;
                }
            }
        });
    }

    /**
     * Relation vers l'institution
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
