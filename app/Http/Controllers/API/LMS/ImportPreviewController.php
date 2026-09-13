<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\ImportPreviewRequest;
use App\Services\Import\ImportPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class ImportPreviewController extends AuthenticatedController
{
    public function __construct(
        private readonly ImportPreviewService $preview,
    ) {}

    public function store(ImportPreviewRequest $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json(['success' => false, 'message' => 'Fichier manquant'], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->preview->preview($file),
        ]);
    }
}
