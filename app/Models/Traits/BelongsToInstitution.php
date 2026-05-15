<?php

namespace App\Models\Traits;

use App\Models\Institution;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Multi-tenant scoping trait — fail-secure.
 *
 * ## Behaviour (CRITICAL-06)
 *
 * - **Query (global scope)** : every query MUST run with a resolved tenant.
 *   If the TenantManager has no current institution, the query throws a
 *   RuntimeException. No silent fall-through — a missing tenant is a security
 *   failure, never a "return everything".
 *
 * - **Creating** : when a new model is being persisted :
 *   - If `institution_id` is already set explicitly (e.g. test fixtures,
 *     supradmin operations, data migrations) → keep the value, do not override.
 *   - If `institution_id` is null → auto-set from TenantManager. If the
 *     TenantManager has no current institution → throw RuntimeException.
 *
 * This pattern allows test fixtures and admin scripts to set institution_id
 * explicitly while preventing production code paths from leaking cross-tenant
 * data through queries.
 *
 * @see PRODUCTION_STANDARDS.md §1.2 + §1.6
 * @see .claude/agents/kfc/spec-security.md Check 5 IDOR / cross-tenant
 */
trait BelongsToInstitution
{
    public static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope('institution', function (Builder $builder) {
            $institutionId = app(TenantManager::class)->id();

            if ($institutionId === null) {
                throw new RuntimeException(
                    'CRITICAL: Tenant resolution failed for ' . $builder->getModel()::class . '. '
                    . 'Cannot query without tenant context. '
                    . 'This is a security failure — refusing to return cross-tenant data.'
                );
            }

            $builder->where(
                $builder->getModel()->getTable() . '.institution_id',
                $institutionId
            );
        });

        static::creating(function (Model $model) {
            // If institution_id has been explicitly assigned (even to null) — keep it.
            // This allows:
            //   - test fixtures: $model->for($institution) sets institution_id
            //   - supradmin/system rows: explicitly set institution_id => null
            //   - data migrations: explicit assignment
            // Detection: array_key_exists checks ASSIGNMENT, distinct from "unset attribute".
            if (array_key_exists('institution_id', $model->getAttributes())) {
                return;
            }

            $institutionId = app(TenantManager::class)->id();

            if ($institutionId === null) {
                throw new RuntimeException(
                    'CRITICAL: Tenant resolution failed for ' . $model::class . '. '
                    . 'Cannot create model without tenant context. '
                    . 'Either initialize the tenant or set institution_id explicitly.'
                );
            }

            $model->institution_id = $institutionId;
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
