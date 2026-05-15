<?php

namespace App\Models\Traits;

use App\Models\Institution;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * Multi-tenant scoping trait — defense-in-depth (CRITICAL-06).
 *
 * ## Strategy : log-and-no-op when the tenant is not resolved
 *
 * Both for queries (global scope) and inserts (creating event), if the
 * TenantManager has no current institution, we **log a WARNING** and let the
 * operation continue without applying the tenant.
 *
 * Why not throw ? In practice, operations without a resolved tenant happen
 * for three legitimate reasons :
 *
 *   (a) Authenticated requests in tests using `Sanctum::actingAs(...)` which
 *       bypass the `ResolveInstitution` middleware.
 *   (b) Controllers that already filter explicitly by `Auth::user()->institution_id`
 *       and do not need the global scope.
 *   (c) Background jobs / commands that operate cross-tenant intentionally.
 *
 * A throw would crash all three. The warning is loud enough to be detected in
 * production logs (and grepped during audits), while cross-tenant data leakage
 * is still prevented upstream by `ResolveInstitution` middleware + per-controller
 * filters.
 *
 * ## Insert hook : explicit institution_id always wins
 *
 *   - If `institution_id` is already assigned (even to null) → keep it.
 *     Detection via `array_key_exists(getAttributes())` distinguishes
 *     "explicit assignment" (factories, supradmin rows, migrations) from
 *     "attribute never touched".
 *   - If `institution_id` is missing and the tenant IS resolved → auto-set.
 *   - If `institution_id` is missing and the tenant is NOT resolved → log
 *     warning and continue (the row will be persisted with whatever DB
 *     default is — typically null on the supradmin path).
 *
 * ## Future hardening
 *
 * Once every factory provides a sensible `institution_id` default and every
 * call site is verified, the creating no-op can be promoted to a strict throw.
 * Tracked as a follow-up after CRITICAL-06.
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
                Log::warning(
                    'BelongsToInstitution: query executed without resolved tenant — scope skipped.',
                    [
                        'model' => $builder->getModel()::class,
                        'note'  => 'Caller should either resolve a tenant or use withoutGlobalScope("institution") explicitly.',
                    ]
                );
                return;
            }

            $builder->where(
                $builder->getModel()->getTable() . '.institution_id',
                $institutionId
            );
        });

        static::creating(function (Model $model) {
            // Explicit assignment (even to null) — keep it.
            // Covers fixtures (factory->for()), supradmin rows (institution_id => null),
            // data migrations.
            if (array_key_exists('institution_id', $model->getAttributes())) {
                return;
            }

            $institutionId = app(TenantManager::class)->id();

            if ($institutionId === null) {
                Log::warning(
                    'BelongsToInstitution: creating model without resolved tenant — institution_id will not be auto-set.',
                    [
                        'model' => $model::class,
                        'note'  => 'Caller should resolve a tenant before persistence, or set institution_id explicitly.',
                    ]
                );
                return;
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
