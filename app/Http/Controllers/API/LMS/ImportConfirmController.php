<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportConfirmController extends AuthenticatedController
{
    public function __construct(
        private readonly Dispatcher $bus,
    ) {}

    public function store(Request $request, int $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $import = Import::query()->findOrFail($id);
        if (! $this->canManage($user, $import) || $import->status !== Import::STATUS_PREVIEWED) {
            return response()->json(['success' => false, 'message' => 'Import non confirmable'], 403);
        }

        $import->update(['status' => Import::STATUS_QUEUED]);
        $this->bus->dispatch(new ProcessImportJob($import->id, (int) $import->institution_id));

        return response()->json([
            'success' => true,
            'data' => ['import_id' => $import->id, 'status' => $import->status],
        ]);
    }

    private function canManage(\App\Models\User $user, Import $import): bool
    {
        return $import->user_id === $user->id
            || $user->isCoordinator()
            || $user->isAdmin();
    }
}
