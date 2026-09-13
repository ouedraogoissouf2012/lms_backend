<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Models\Import;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportShowController extends AuthenticatedController
{
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $import = Import::query()->with('rows')->findOrFail($id);
        if ($import->user_id !== $user->id && ! $user->isCoordinator() && ! $user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Interdit'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'import_id' => $import->id,
                'status' => $import->status,
                'counts' => [
                    'ok' => $import->ok_count,
                    'error' => $import->error_count,
                ],
                'rows' => $import->rows->map(static fn ($row): array => [
                    'line' => $row->line,
                    'status' => $row->status,
                    'code' => $row->code,
                    'message' => $row->message,
                ]),
            ],
        ]);
    }
}
