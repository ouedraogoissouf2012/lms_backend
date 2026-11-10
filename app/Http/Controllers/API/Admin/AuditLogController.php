<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ViewAuditLogRequest;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

/**
 * Consultation du journal d'audit (#215) — supradmin uniquement.
 *
 * Lecture seule, filtrable (action, user_id), paginée et triée du plus récent
 * au plus ancien. Aucune route d'écriture/suppression : le journal est
 * append-only (rétention gérée par la commande `audit:purge`).
 *
 * L'autorisation supradmin est portée par {@see ViewAuditLogRequest}.
 *
 * @see app/Models/AuditLog.php
 */
final class AuditLogController extends Controller
{
    /**
     * GET /api/admin/audit-log
     */
    public function index(ViewAuditLogRequest $request): JsonResponse
    {
        // Helpers typés de la Request (string/integer) plutôt que des casts de
        // `mixed` issus de validated() — conforme PHPStan niveau 9.
        //
        // L'auteur et l'école de chaque entrée, chargés en une requête chacun
        // (D127). Supprimés depuis, ils restent servis : c'est le cas qu'on
        // diagnostique. La lecture est plateforme (D51) : aucun scope de tenant
        // ne masque l'auteur d'une autre école. `id` est gardé dans chaque
        // sélection : c'est la clé de la jointure.
        $query = AuditLog::withoutGlobalScope('institution')
            ->with([
                'user' => static fn ($auteur) => $auteur->withTrashed()
                    ->withoutGlobalScope('institution')
                    ->select('id', 'name', 'email', 'role'),
                'institution' => static fn ($ecole) => $ecole->withTrashed()->select('id', 'name'),
            ])
            ->recent();

        if ($request->filled('action')) {
            $query->forAction($request->string('action')->value());
        }

        if ($request->filled('user_id')) {
            $query->forUser($request->integer('user_id'));
        }

        $perPage = $request->integer('per_page', 50);

        return $this->successResponse($query->paginate($perPage));
    }
}
