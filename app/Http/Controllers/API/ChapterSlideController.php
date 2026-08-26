<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Services\Chapter\ChapterSlideService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * #620 — diapositive PNG derrière une URL signée (plus de /storage/ prédictible).
 *
 * Sans `auth:sanctum` : une balise `<img>` ne peut pas envoyer le Bearer.
 * La signature (émise par un `GET /api/chapters/{id}` authentifié) est le
 * jeton d'accès. Le chapitre est chargé hors scope tenant : l'URL signée
 * lie déjà l'id ; exiger un tenant casserait l'`<img>` anonyme.
 */
final class ChapterSlideController extends Controller
{
    public function __construct(
        private readonly ChapterSlideService $slides,
    ) {
    }

    public function show(int $chapter, int $slide): StreamedResponse|JsonResponse
    {
        $model = Chapter::withoutGlobalScopes()->find($chapter);
        if ($model === null) {
            return $this->errorResponse('Diapositive introuvable', 404);
        }

        $stream = $this->slides->stream($model, $slide);
        if ($stream === null) {
            return $this->errorResponse('Diapositive introuvable', 404);
        }

        return $stream;
    }
}
