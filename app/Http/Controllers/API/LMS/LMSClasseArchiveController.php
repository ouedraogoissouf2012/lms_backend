<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ClasseLocaleEnListe;
use App\Models\Classe;
use App\Services\Catalogue\ArchiveDeClasse;
use Closure;
use Illuminate\Http\JsonResponse;

/**
 * Archiver une classe locale, ou la désarchiver (#1652) : deux gestes NOMMÉS, sur le modèle de
 * la capacité (#1358). Chacun rend la ligne de la collection (`ClasseLocaleEnListe`) : l'écran
 * la remplace sur place. Aucune lecture du mode ici : le service passe par la porte du
 * catalogue, et le contrôleur relaie son refus.
 */
final class LMSClasseArchiveController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly ArchiveDeClasse $archives) {}

    public function archiver(int $classe): JsonResponse
    {
        return $this->rendre(
            fn (): Classe => $this->archives->archiver($classe),
            'Classe archivée : elle ne reçoit plus d\'inscription, son code est fermé.',
        );
    }

    public function desarchiver(int $classe): JsonResponse
    {
        return $this->rendre(
            fn (): Classe => $this->archives->desarchiver($classe),
            'Classe désarchivée. Son code reste fermé : émettez-en un nouveau pour rouvrir les inscriptions.',
        );
    }

    /** @param Closure(): Classe $geste */
    private function rendre(Closure $geste, string $message): JsonResponse
    {
        try {
            $classe = $geste();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(ClasseLocaleEnListe::de($classe), $message);
    }
}
