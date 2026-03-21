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
        static::addGlobalScope('institution', function (Builder $builder) {
            $tenantManager = app(TenantManager::class);
            if ($tenantManager->id()) {
                $builder->where(
                    $builder->getModel()->getTable() . '.institution_id',
                    $tenantManager->id()
                );
            }
        });

        // AUTO-SET : définir institution_id à la création
        static::creating(function (Model $model) {
            if (!$model->institution_id) {
                $tenantManager = app(TenantManager::class);
                $model->institution_id = $tenantManager->id();
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
