<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\Concerns\ChecksChapterDownloadAuthorization;
use App\Models\Chapter;
use App\Services\Chapter\ChapterOriginalDownloadService;
use App\Services\Chapter\ChapterReadGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Issue #598 — téléchargement **authentifié** du document source d'un chapitre.
 *
 * Avant ce correctif, le document source vivait sur le disque public et était
 * servi par Apache via `/storage/chapters/{id}/original/<hash>.docx`, sans
 * authentification ni cloisonnement d'institution. Il vit désormais sur le
 * disque privé, et cette route est le **seul** chemin d'accès.
 *
 * Contrôleur dédié plutôt qu'une méthode de plus sur `ChapterController` : la
 * seule action ici a sa propre autorisation (le document source est plus
 * sensible que le chapitre rendu) et son propre type de réponse (flux binaire).
 *
 * @see PRODUCTION_STANDARDS.md §5 — Controllers ≤200 lignes
 * @see PRODUCTION_STANDARDS.md §1.2 — aucun endpoint sans auth + rôle vérifié
 */
final class ChapterOriginalController extends AuthenticatedController
{
    use ChecksChapterDownloadAuthorization;

    public function __construct(
        private readonly ChapterOriginalDownloadService $downloads,
        private readonly ChapterReadGate $reads,
    ) {
    }

    /**
     * GET /api/chapters/{id}/original — document source du chapitre.
     *
     * Codes de retour :
     *   - **401** : non authentifié (posé par `auth:sanctum` en amont).
     *   - **404** : chapitre introuvable **ou d'une autre institution** — le
     *     route-model binding résout sous le scope global `BelongsToInstitution`,
     *     qui rend le chapitre inexistant pour cette requête. 404 plutôt que
     *     403 : un 403 confirmerait l'existence de la ressource (oracle
     *     d'énumération), et c'est déjà ce que renvoie `GET /api/chapters/{id}`.
     *     Distinguer les deux exigerait de requêter HORS scope tenant, donc
     *     d'affaiblir l'isolation pour améliorer un message d'erreur.
     *   - **403** : chapitre visible dans le tenant, mais l'appelant n'a pas le
     *     droit d'en télécharger la source (`allow_download` désactivé).
     *   - **404** : chapitre sans document source, ou artefact purgé.
     *
     * Le chapitre arrive par **route-model binding** : §5 interdit l'accès DB
     * direct en contrôleur, et la résolution du binding applique le même scope
     * tenant que ferait un `find()` manuel.
     */
    public function show(Request $request, Chapter $chapter): StreamedResponse|JsonResponse
    {
        $user = $this->authenticatedUser($request);

        if (! $this->reads->canRead($chapter, $user)) {
            return $this->errorResponse('Chapitre non trouvé', 404);
        }

        if (! $this->canDownloadChapterOriginal($chapter, $user)) {
            return $this->errorResponse('Accès refusé', 403);
        }

        $stream = $this->downloads->download($chapter);

        if ($stream === null) {
            return $this->errorResponse('Aucun document source pour ce chapitre', 404);
        }

        return $stream;
    }
}
