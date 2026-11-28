<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Presenters\SousPeriodeEnVue;
use App\Http\Requests\EcrireUneSousPeriodeRequest;
use App\Services\Session\SousPeriodesDeLaPeriode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les sous-périodes d'une période (#1806), en routes imbriquées sous la période. Aucune lecture du mode ici : le
 * service passe par la porte du catalogue (via `PeriodesDeLEcole`), et le contrôleur relaie son refus.
 */
final class LMSSousPeriodeController extends AuthenticatedController
{
    use RespondsWithJson;

    public function __construct(private readonly SousPeriodesDeLaPeriode $sousPeriodes) {}

    public function index(Request $request, int $trainingSession): JsonResponse
    {
        return $this->rendre(fn (): array => array_map(
            SousPeriodeEnVue::de(...),
            $this->sousPeriodes->lister($this->authenticatedUser($request), $trainingSession),
        ));
    }

    public function store(EcrireUneSousPeriodeRequest $request, int $trainingSession): JsonResponse
    {
        /** @var array{libelle: string, debut: string, fin: string, ordre?: ?int} $donnees */
        $donnees = $request->donnees();

        return $this->rendre(fn (): array => SousPeriodeEnVue::de(
            $this->sousPeriodes->creer($this->authenticatedUser($request), $trainingSession, $donnees),
        ), 201, 'Sous-période créée.');
    }

    public function update(EcrireUneSousPeriodeRequest $request, int $trainingSession, int $sousPeriode): JsonResponse
    {
        return $this->rendre(fn (): array => SousPeriodeEnVue::de(
            $this->sousPeriodes->modifier($this->authenticatedUser($request), $trainingSession, $sousPeriode, $request->donnees()),
        ), 200, 'Sous-période modifiée.');
    }

    public function destroy(Request $request, int $trainingSession, int $sousPeriode): JsonResponse
    {
        return $this->rendre(function () use ($request, $trainingSession, $sousPeriode): ?array {
            $this->sousPeriodes->supprimer($this->authenticatedUser($request), $trainingSession, $sousPeriode);

            return null;
        }, 200, 'Sous-période supprimée.');
    }

    /** @param Closure(): (array<int|string, mixed>|null) $geste */
    private function rendre(Closure $geste, int $statut = 200, string $message = ''): JsonResponse
    {
        try {
            $data = $geste();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse($data, $message, $statut);
    }
}
