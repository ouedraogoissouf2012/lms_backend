<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\LinkClasseMatiereRequest;
use App\Services\Catalogue\LocalClasseMatiereLinker;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Composer le programme d'une classe (#848).
 *
 * Troisième et dernier lot de l'ADR-848-01 : après la Classe (#860) et la
 * Matière, ce qui les relie — et le formateur qui l'assure.
 *
 * Écrit `classe_matiere`, le pivot LOCAL que `LocalEnrollmentSource:35-40` lit
 * déjà. `matiere_enseignant` n'est pas touchée : c'est le miroir KLASSCI.
 */
final class LMSClasseMatiereController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly LocalClasseMatiereLinker $liens) {}

    public function store(LinkClasseMatiereRequest $request, int $classe): JsonResponse
    {
        try {
            $rattachee = $this->liens->rattacher(
                $classe,
                $request->matiereId(),
                $request->enseignantId(),
            );
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $this->statut($e));
        }

        return $this->successResponse([
            'classe_id' => $rattachee->getKey(),
            'matiere_id' => $request->matiereId(),
            'enseignant_id' => $request->enseignantId(),
        ], 'Matière rattachée à la classe.', 201);
    }

    private function statut(Throwable $e): int
    {
        $code = $e->getCode();

        return is_int($code) && $code >= 400 && $code < 600 ? $code : 422;
    }
}
