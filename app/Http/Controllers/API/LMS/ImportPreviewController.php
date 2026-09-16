<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Enums\Role;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\ImportPreviewRequest;
use App\Services\Import\ColumnMap;
use App\Services\Import\ImportPreviewFailed;
use App\Services\Import\ImportPreviewService;
use App\Services\Import\ImportRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class ImportPreviewController extends AuthenticatedController
{
    public function __construct(
        private readonly ImportPreviewService $preview,
        private readonly ImportRecorder $recorder,
    ) {}

    public function store(ImportPreviewRequest $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json(['success' => false, 'message' => 'Fichier manquant'], 422);
        }

        $user = $this->authenticatedUser($request);

        try {
            $report = $this->preview->preview(
                $file,
                ColumnMap::fromRequest($request->mappingInput()),
                // Le plafond des comptes créés est le rôle de l'importateur, pris
                // ici et jamais dans le fichier. `asRoleEnum()` rend null si la
                // colonne `users.role` porte une graphie inconnue : on retombe
                // alors sur le moindre privilège plutôt que sur une exception.
                $user->asRoleEnum() ?? Role::Etudiant,
                $request->delimiterInput(),
            );
        } catch (ImportPreviewFailed $e) {
            // Le fichier n'a pas pu être analysé du tout : pas de rapport à
            // rendre, et un rapport vide se lirait à tort comme « zéro erreur ».
            return response()->json([
                'success' => false,
                'code' => $e->machineCode(),
                'message' => $e->getMessage(),
            ], 422);
        }

        $import = $this->recorder->persist($user, $file, $report);

        return response()->json([
            'success' => true,
            'data' => [
                'import_id' => $import->id,
                'rows' => $report['rows'],
                'counts' => $report['counts'],
            ],
        ]);
    }
}
