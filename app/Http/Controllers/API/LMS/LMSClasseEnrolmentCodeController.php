<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Services\Enrollment\ClasseEnrolmentCodeService;
use Illuminate\Http\JsonResponse;

/**
 * Émettre et retirer le code d'inscription d'une classe (#846, lot B).
 *
 * Le code est rendu **en clair**, et c'est voulu : contrairement au lien
 * d'activation d'ADR-803-02, il circule par téléphone et par WhatsApp, se
 * réaffiche autant que nécessaire, et se révoque. Le hacher le rendrait
 * indictable.
 *
 * Aucune lecture du mode d'établissement : le service consulte une CAPACITÉ et
 * le contrôleur relaie son refus (contrat OCP, #697 article 1).
 */
final class LMSClasseEnrolmentCodeController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly ClasseEnrolmentCodeService $codes) {}

    public function store(int $classe): JsonResponse
    {
        try {
            $code = $this->codes->generer($classe);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp());
        }

        return $this->successResponse(
            ['classe_id' => $classe, 'code_inscription' => $code],
            'Code d\'inscription émis. Le précédent ne fonctionne plus.',
            201
        );
    }

    public function destroy(int $classe): JsonResponse
    {
        try {
            $this->codes->revoquer($classe);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp());
        }

        return $this->successResponse(
            null,
            'Code retiré. Les inscriptions déjà faites sont conservées.'
        );
    }
}
