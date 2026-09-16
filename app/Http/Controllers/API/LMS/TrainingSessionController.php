<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\StoreProgramRequest;
use App\Http\Requests\StoreTrainingSessionRequest;
use App\Models\TrainingSession;
use App\Services\Session\TrainingSessionCrudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $programme = $this->sessions->creerProgramme(
            $this->authenticatedUser($request),
            $request->titre(),
            $request->description(),
        );

        return $this->successResponse([
            'id' => $programme->getKey(),
            'titre' => $programme->titre,
            'version' => $programme->version,
        ], 'Programme créé.', 201);
    }

    public function store(StoreTrainingSessionRequest $request): JsonResponse
    {
        $periode = $this->sessions->creerPeriode(
            $this->authenticatedUser($request),
            $request->validated(),
        );

        return $this->successResponse($this->enVue($periode), 'Session de formation créée.', 201);
    }

    public function index(Request $request): JsonResponse
    {
        $parPage = min(max($request->integer('per_page', 25), 1), 100);

        $page = $this->sessions->listerLesPeriodes($this->authenticatedUser($request), $parPage);

        return $this->successResponse(
            array_map(fn (TrainingSession $p): array => $this->enVue($p), $page->items()),
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

    /**
     * `phase` est DÉRIVÉE des dates : elle n'existe en base nulle part, et c'est
     * ici qu'elle devient visible du client (ADR-711-04).
     *
     * @return array<string, mixed>
     */
    private function enVue(TrainingSession $periode): array
    {
        return [
            'id' => $periode->getKey(),
            'libelle' => $periode->libelle,
            'status' => $periode->status->value,
            'phase' => $periode->phase->value,
            'program_id' => $periode->program_id,
            'enrollment_opens_at' => $periode->enrollment_opens_at?->toDateString(),
            'enrollment_closes_at' => $periode->enrollment_closes_at?->toDateString(),
            'starts_on' => $periode->starts_on?->toDateString(),
            'ends_on' => $periode->ends_on?->toDateString(),
            'certificate_available_at' => $periode->certificate_available_at?->toDateString(),
            'min_enrollments' => $periode->min_enrollments,
            'tarif' => $periode->tarif,
            'devise' => $periode->devise,
        ];
    }
}
