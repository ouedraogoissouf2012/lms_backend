<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation;

use App\Exceptions\BusinessException;
use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DispenserUnEleveRequest;
use App\Models\DispenseDEvaluation;
use App\Services\Evaluation\Classe\DispensesDEvaluation;
use App\Services\Evaluation\Classe\EleveDeLaClasse;
use App\Services\Evaluation\Classe\PorteDesDispenses;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * #1698 (C2) : `PUT` et `DELETE /api/evaluations/{id}/dispenses` — dispenser un élève d'une
 * évaluation, ou lever sa dispense. L'accès est tranché par {@see DispenserUnEleveRequest}.
 *
 * Un refus métier (élève hors de la classe, évaluation sans classe, école KLASSCI) est rendu avec son statut et
 * son motif ; un refus ou une panne du roster KLASSCI, par {@see RendersKlassciBackedErrors}.
 *
 * Le service et la porte sont reçus par la MÉTHODE, jamais par le constructeur : leurs sources se choisissent sur
 * l'école courante, et Laravel garde le contrôleur sur l'objet Route (une source capturée à la construction
 * servirait la première école à toutes les suivantes).
 */
final class EvaluationDispenseController extends AuthenticatedController
{
    use RendersKlassciBackedErrors;

    public function accorder(DispenserUnEleveRequest $request, int $id, DispensesDEvaluation $dispenses, PorteDesDispenses $porte): JsonResponse
    {
        return $this->agir($request, $id, $dispenses, $porte, true);
    }

    public function lever(DispenserUnEleveRequest $request, int $id, DispensesDEvaluation $dispenses, PorteDesDispenses $porte): JsonResponse
    {
        return $this->agir($request, $id, $dispenses, $porte, false);
    }

    private function agir(DispenserUnEleveRequest $request, int $id, DispensesDEvaluation $dispenses, PorteDesDispenses $porte, bool $accorder): JsonResponse
    {
        $auteur = $this->authenticatedUser($request);

        try {
            $evaluation = $dispenses->evaluation($id, $auteur);
            $porte->ouvrir();
            $eleve = $dispenses->eleve($evaluation, $auteur, $request->compte(), $request->identiteKlassci());
            if ($accorder) {
                $dispense = $dispenses->accorder($evaluation, $eleve, $auteur, $request->motif());
            } else {
                $dispenses->lever($evaluation, $eleve);
                $dispense = null;
            }

            return $this->successResponse(self::etat((int) $evaluation->id, $eleve, $dispense));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        }
    }

    /** @return array{evaluation_id: int, compte_id: int|null, klassci_etudiant_id: int|null, dispense: bool, motif: string|null} */
    private static function etat(int $evaluation, EleveDeLaClasse $eleve, ?DispenseDEvaluation $dispense): array
    {
        return [
            'evaluation_id' => $evaluation,
            'compte_id' => $eleve->compte,
            'klassci_etudiant_id' => $eleve->identitesKlassci[0] ?? null,
            'dispense' => $dispense !== null,
            'motif' => $dispense?->motif,
        ];
    }
}
