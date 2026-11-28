<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation;

use App\Enums\StatutDeReclamation;
use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeposerUneReclamationRequest;
use App\Http\Requests\DonnerUnAvisSurUneReclamationRequest;
use App\Http\Requests\TrancherUneReclamationRequest;
use App\Models\ReclamationDeNote;
use App\Models\User;
use App\Services\Evaluation\Reclamation\ReclamationsDeNotes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1678 : les réclamations de notes. L'élève dépose, l'enseignant donne son avis, la gestion
 * tranche ; chacun lit les siennes avec leurs compteurs (`GET /api/reclamations`). Les droits des
 * étapes sont tranchés par les requêtes ; le circuit par {@see ReclamationsDeNotes}.
 */
final class ReclamationsDeNotesController extends AuthenticatedController
{
    public function __construct(private readonly ReclamationsDeNotes $reclamations) {}

    public function deposer(DeposerUneReclamationRequest $request, int $id): JsonResponse
    {
        return $this->etape(fn (): ReclamationDeNote => $this->reclamations->deposer($id, $this->authenticatedUser($request), $request->motif()), 201);
    }

    public function lister(Request $request): JsonResponse
    {
        $lecteur = $this->authenticatedUser($request);
        $statut = StatutDeReclamation::tryFrom((string) $request->query('statut', ''));

        return $this->successResponse([
            'compteurs' => $this->reclamations->compteurs($lecteur),
            'reclamations' => array_values($this->reclamations->visibles($lecteur, $statut)
                ->map(static fn (ReclamationDeNote $r): array => self::presenter($r))->all()),
        ]);
    }

    public function avis(DonnerUnAvisSurUneReclamationRequest $request, int $id, int $reclamation): JsonResponse
    {
        $auteur = $this->authenticatedUser($request);
        $note = $request->validated('note_proposee');
        $commentaire = $request->validated('commentaire');

        return $this->etape(fn (): ReclamationDeNote => $this->reclamations->donnerAvis(
            $this->reclamations->de($id, $reclamation, $auteur), $auteur, (string) $request->validated('avis'),
            is_string($commentaire) ? $commentaire : null, is_numeric($note) ? (float) $note : null,
        ));
    }

    public function trancher(TrancherUneReclamationRequest $request, int $id, int $reclamation): JsonResponse
    {
        $auteur = $this->authenticatedUser($request);
        $note = $request->validated('note_decidee');
        $commentaire = $request->validated('commentaire');

        return $this->etape(fn (): ReclamationDeNote => $this->reclamations->trancher(
            $this->reclamations->de($id, $reclamation, $auteur), $auteur, (string) $request->validated('decision'),
            is_numeric($note) ? (float) $note : null, is_string($commentaire) ? $commentaire : null,
        ));
    }

    /** @param  callable(): ReclamationDeNote  $geste */
    private function etape(callable $geste, int $statut = 200): JsonResponse
    {
        try {
            return $this->successResponse(self::presenter($geste()->refresh()->load(['copie', 'evaluation'])), status: $statut);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    /** @return array<string, mixed> */
    private static function presenter(ReclamationDeNote $r): array
    {
        $eleve = User::query()->withoutGlobalScope('institution')->find($r->student_id, ['id', 'name']);

        return [
            'id' => $r->id,
            'evaluation_id' => $r->evaluation_id,
            'evaluation_titre' => $r->evaluation?->titre,
            'copie_id' => $r->evaluation_submission_id,
            'eleve' => ['compte_id' => $r->student_id, 'nom' => $eleve?->name],
            'motif' => $r->motif,
            'statut' => $r->statut->value,
            'note_contestee' => $r->note_contestee === null ? null : (float) $r->note_contestee,
            'avis' => $r->avis === null ? null : [
                'avis' => $r->avis, 'commentaire' => $r->avis_commentaire,
                'note_proposee' => $r->note_proposee === null ? null : (float) $r->note_proposee, 'le' => $r->avis_le?->toIso8601String(),
            ],
            'decision' => $r->decision === null ? null : [
                'decision' => $r->decision, 'commentaire' => $r->decision_commentaire,
                'note_decidee' => $r->note_decidee === null ? null : (float) $r->note_decidee, 'le' => $r->decision_le?->toIso8601String(),
            ],
            'klassci' => ReclamationsDeNotes::etatKlassci($r),
            'depose_le' => $r->created_at?->toIso8601String(),
        ];
    }
}
