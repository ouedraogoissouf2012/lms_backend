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
        // CRITICAL-06: Fail-secure - lance exception si tenant non résolu
        static::addGlobalScope('institution', function (Builder $builder) {
            $tenantManager = app(TenantManager::class);
            $institutionId = $tenantManager->getResolved(); // Throws if not initialized

            $builder->where(
                $builder->getModel()->getTable() . '.institution_id',
                $institutionId
            );
        });

        // AUTO-SET : définir institution_id à la création
        static::creating(function (Model $model) {
            if (!$model->institution_id) {
                $tenantManager = app(TenantManager::class);
                $model->institution_id = $tenantManager->getResolved(); // Throws if not initialized
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
