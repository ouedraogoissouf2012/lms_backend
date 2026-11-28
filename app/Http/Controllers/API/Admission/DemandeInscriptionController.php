<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admission;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\ListerDemandesInscriptionRequest;
use App\Models\DemandeInscription;
use App\Services\Admission\TraitementDesDemandes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les demandes d'inscription, côté gestion d'une école autonome (#1659, PR 1) : liste, consultation, examen, rejet.
 * Les règles vivent dans {@see TraitementDesDemandes}.
 */
final class DemandeInscriptionController extends AuthenticatedController
{
    public function __construct(private readonly TraitementDesDemandes $demandes) {}

    public function index(ListerDemandesInscriptionRequest $request): JsonResponse
    {
        return $this->traduire(function () use ($request): JsonResponse {
            ['page' => $page, 'compteurs' => $compteurs] = $this->demandes->lister($this->authenticatedUser($request), $request->validated());
            $lignes = [];
            foreach ($page->items() as $demande) {
                $lignes[] = $this->forme($demande);
            }

            return $this->successResponse([
                'data' => $lignes, 'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage(),
            ], '', 200, ['counts' => $compteurs]);
        });
    }

    public function show(Request $request, int $demande): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse($this->forme($this->demandes->voir($this->authenticatedUser($request), $demande))));
    }

    public function examiner(Request $request, int $demande): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->forme($this->demandes->examiner($this->authenticatedUser($request), $demande)),
            'Demande en examen',
        ));
    }

    public function rejeter(Request $request, int $demande): JsonResponse
    {
        $motif = $request->validate(['motif' => ['required', 'string', 'max:500']])['motif'];

        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->forme($this->demandes->rejeter($this->authenticatedUser($request), $demande, is_string($motif) ? $motif : '')),
            'Demande rejetée',
        ));
    }

    /** @return array<string, mixed> */
    private function forme(DemandeInscription $d): array
    {
        return [
            'id' => $d->id, 'type' => $d->type, 'etat' => $d->etat->value,
            'nom' => $d->nom, 'prenoms' => $d->prenoms, 'date_naissance' => $d->date_naissance?->toDateString(), 'genre' => $d->genre,
            'declarant_nom' => $d->declarant_nom, 'declarant_relation' => $d->declarant_relation,
            'declarant_telephone' => $d->declarant_telephone, 'declarant_courriel' => $d->declarant_courriel,
            'classe_souhaitee' => $d->classe_souhaitee, 'diplome_entree' => $d->diplome_entree,
            'motif_rejet' => $d->motif_rejet, 'traite_le' => $d->traite_le?->toIso8601String(),
            'depose_le' => $d->created_at?->toIso8601String(),
        ];
    }

    /** @param  callable(): JsonResponse  $acte */
    private function traduire(callable $acte): JsonResponse
    {
        try {
            return $acte();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }
}
