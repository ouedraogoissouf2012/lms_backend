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
        $filters = $request->validated();

        $query = AuditLog::query()->with('user:id,name,email,role')->recent();

        if (isset($filters['action'])) {
            $query->forAction($filters['action']);
        }

        if (isset($filters['user_id'])) {
            $query->forUser((int) $filters['user_id']);
        }

        $perPage = (int) ($filters['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
        ]);
    }
}
