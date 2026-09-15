<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\User;
use App\Services\Roster\RosterAuthority;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportConfirmController extends AuthenticatedController
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly RosterAuthority $roster,
    ) {}

    public function store(Request $request, int $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $import = Import::query()->findOrFail($id);

        // `canManage` répond « est-ce SON import », jamais « cet établissement
        // tient-il sa propre liste ». C'est l'étape qui écrit vraiment : un
        // import créé avant une bascule de mode ne doit pas pouvoir aboutir.
        if (! $this->roster->allowsLocalEnrolment()) {
            return response()->json(['success' => false, 'message' => 'Import non confirmable'], 403);
        }

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

    private function canManage(User $user, Import $import): bool
    {
        return $import->user_id === $user->id
            || $user->isCoordinator()
            || $user->isAdmin();
    }
}
