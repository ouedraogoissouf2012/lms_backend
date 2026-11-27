<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Presenters\PeriodeEnVue;
use App\Http\Requests\ListerLesPeriodesRequest;
use App\Http\Requests\ListerLesProgrammesRequest;
use App\Http\Requests\StoreProgramRequest;
use App\Http\Requests\StoreTrainingSessionRequest;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Services\Session\TrainingSessionCrudService;
use Illuminate\Http\JsonResponse;

/**
 * Créer une session de formation (#827).
 *
 * #800 a livré les tables ; ce contrôleur est ce qui manquait pour s'en servir.
 * C'est le premier parcours réellement utile du monde autonome : un responsable
 * crée son Programme, puis la Période datée qui le fera vivre.
 *
 * Aucune lecture du mode d'établissement ici : les appelants reçoivent une
 * capacité, jamais un mode (contrat OCP, #697 article 1). Les tables sont
 * tenant-scopées — toute institution peut porter des Programmes.
 */
final class TrainingSessionController extends AuthenticatedController
{
    public function __construct(private readonly TrainingSessionCrudService $sessions) {}

    public function storeProgram(StoreProgramRequest $request): JsonResponse
    {
        try {
            $programme = $this->sessions->creerProgramme(
                $this->authenticatedUser($request),
                $request->titre(),
                $request->description(),
            );
        } catch (BusinessException $e) {
            return $this->refus($e);
        }

        return $this->successResponse([
            'id' => $programme->getKey(),
            'titre' => $programme->titre,
            'version' => $programme->version,
        ], 'Programme créé.', 201);
    }

    /** Les Programmes de l'établissement, pour choisir celui d'une Période (#1206). */
    public function indexPrograms(ListerLesProgrammesRequest $request): JsonResponse
    {
        try {
            $page = $this->sessions->listerLesProgrammes($this->authenticatedUser($request), $request->parPage());
        } catch (BusinessException $e) {
            return $this->refus($e);
        }

        return $this->successResponse(
            array_map(static fn (Program $p): array => ['id' => $p->getKey(), 'titre' => $p->titre, 'version' => $p->version], $page->items()),
            '',
            200,
            ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }

    public function store(StoreTrainingSessionRequest $request): JsonResponse
    {
        try {
            $periode = $this->sessions->creerPeriode($this->authenticatedUser($request), $request->validated());
        } catch (BusinessException $e) {
            return $this->refus($e);
        }

        return $this->successResponse(PeriodeEnVue::de($periode), 'Session de formation créée.', 201);
    }

    public function index(ListerLesPeriodesRequest $request): JsonResponse
    {
        // Validée, jamais ramenée (#911) : `?per_page=abc` rend 422.
        try {
            $page = $this->sessions->listerLesPeriodes(
                $this->authenticatedUser($request),
                $request->parPage(),
                $request->avecLesArchivees(),
            );
        } catch (BusinessException $e) {
            return $this->refus($e);
        }

        return $this->successResponse(
            array_map(static fn (TrainingSession $p): array => PeriodeEnVue::de($p), $page->items()),
            '',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    private function refus(BusinessException $e): JsonResponse
    {
        return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
    }
}
