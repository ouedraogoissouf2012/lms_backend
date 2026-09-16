<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Services\Visio\Recording\RecordingVideoService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * #824 — enregistrement de séance derrière une URL signée.
 *
 * Sans `auth:sanctum`, pour la même raison qu'en #620 : une balise `<video>`
 * ne peut pas envoyer le Bearer. La signature — émise uniquement dans la
 * réponse d'un `GET /api/chapters/{id}` authentifié — est le jeton d'accès.
 * Le chapitre est chargé hors scope tenant : l'URL signée lie déjà l'id.
 *
 * Le 404 est volontairement indistinct : « ce chapitre n'est pas un
 * enregistrement » et « le fichier a été purgé » rendent la même réponse, pour
 * qu'une signature valide ne renseigne jamais sur l'état du disque.
 */
final class ChapterVideoController extends Controller
{
    public function __construct(
        private readonly RecordingVideoService $video,
    ) {}

    public function show(int $chapter): BinaryFileResponse|JsonResponse
    {
        $model = Chapter::withoutGlobalScopes()->find($chapter);

        if ($model === null) {
            return $this->errorResponse('Enregistrement introuvable', 404);
        }

        $response = $this->video->stream($model);

        if ($response === null) {
            return $this->errorResponse('Enregistrement introuvable', 404);
        }

        return $response;
    }
}
