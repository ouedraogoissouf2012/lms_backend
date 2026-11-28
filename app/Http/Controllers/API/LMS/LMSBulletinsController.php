<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\EcrireLeModeleDeBulletinRequest;
use App\Http\Requests\EcrireUneAppreciationRequest;
use App\Http\Requests\LireLesAppreciationsRequest;
use App\Models\AppreciationDeBulletin;
use App\Services\Bulletin\AppreciationsDeBulletin;
use App\Services\Bulletin\ModelesDeBulletin;
use App\Services\Evaluation\Classe\AccesAuBilanDeClasse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1677 (B1) : le modèle de bulletin de l'école, et les appréciations d'une classe pour une
 * période. La classe se lit comme celle du bilan (`AccesAuBilanDeClasse` : l'identifiant de
 * l'espace source, 404 hors de l'école, l'enseignant de la classe seulement) ; dans une école
 * KLASSCI, 403 `report_cards_managed_by_klassci`.
 */
final class LMSBulletinsController extends AuthenticatedController
{
    public function __construct(
        private readonly ModelesDeBulletin $modeles,
        private readonly AppreciationsDeBulletin $appreciations,
        private readonly AccesAuBilanDeClasse $acces,
    ) {}

    public function modele(Request $request): JsonResponse
    {
        return $this->repondre(fn (): array => $this->modeles->lire($this->authenticatedUser($request)));
    }

    public function enregistrerModele(EcrireLeModeleDeBulletinRequest $request): JsonResponse
    {
        return $this->repondre(fn (): array => $this->modeles->enregistrer($this->authenticatedUser($request), $request->validated()));
    }

    public function appreciations(LireLesAppreciationsRequest $request, int $classe): JsonResponse
    {
        $lecteur = $this->authenticatedUser($request);

        return $this->repondre(function () use ($lecteur, $classe, $request): array {
            $lue = $this->acces->classe($classe, $lecteur);
            $this->acces->portee($lue, $lecteur);

            return array_values($this->appreciations->de($lue, $request->periode(), $lecteur)
                ->map(static fn (AppreciationDeBulletin $a): array => self::appreciation($a))->all());
        });
    }

    public function ecrireAppreciation(EcrireUneAppreciationRequest $request, int $classe): JsonResponse
    {
        $auteur = $this->authenticatedUser($request);
        $matiere = $request->validated('matiere_id');
        $texte = $request->validated('texte');
        $decision = $request->validated('decision');

        return $this->repondre(function () use ($auteur, $classe, $request, $matiere, $texte, $decision): array {
            $lue = $this->acces->classe($classe, $auteur);
            $this->acces->portee($lue, $auteur);

            return self::appreciation($this->appreciations->ecrire(
                $lue, $request->periode(), $auteur, (int) $request->validated('eleve_id'),
                is_numeric($matiere) ? (int) $matiere : null, is_string($texte) ? $texte : null, is_string($decision) ? $decision : null,
            ));
        });
    }

    /** @param  callable(): array<mixed>  $geste */
    private function repondre(callable $geste): JsonResponse
    {
        try {
            return $this->successResponse($geste());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    /** @return array<string, mixed> */
    private static function appreciation(AppreciationDeBulletin $a): array
    {
        return [
            'id' => $a->id,
            'eleve_id' => $a->student_id,
            'matiere_id' => $a->matiere_id,
            'periode' => ['libelle' => $a->periode_libelle, 'du' => $a->periode_du->toDateString(), 'au' => $a->periode_au->toDateString()],
            'texte' => $a->texte,
            'decision' => $a->decision,
            'auteur_id' => $a->auteur_id,
        ];
    }
}
