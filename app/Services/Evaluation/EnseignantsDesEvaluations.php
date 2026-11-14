<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * L'enseignant d'une évaluation présentée ({@see EvaluationEnrichmentService}).
 *
 * Sorti de l'enrichissement quand celui-ci dépassait la garde de taille (revue
 * d'E3), sans changement de comportement :
 * - identité KLASSCI (`klassci_enseignant_id`) : le compte local qui la porte,
 *   comme avant ;
 * - sinon, l'auteur local (`enseignant_id`, D72), borné à l'établissement de
 *   l'évaluation.
 *
 * Les auteurs locaux sont préchargés en UNE requête pour toute la liste, et non
 * une par évaluation : la liste du personnel en rend jusqu'à 100.
 */
final class EnseignantsDesEvaluations
{
    /**
     * @param  Collection<int, Evaluation>  $evaluations
     * @return \Closure(Evaluation): array{enseignant: array<string, mixed>|null, enseignant_nom: string|null}
     */
    public function de(Collection $evaluations): \Closure
    {
        $auteurs = $this->auteursLocaux($evaluations);

        return fn (Evaluation $evaluation): array => $evaluation->klassci_enseignant_id
            ? $this->parIdentiteKlassci($evaluation)
            : self::auteurLocal($evaluation, $auteurs);
    }

    /**
     * @return array{enseignant: array<string, mixed>|null, enseignant_nom: string|null}
     */
    private function parIdentiteKlassci(Evaluation $evaluation): array
    {
        $enseignant = User::where('klassci_id', $evaluation->klassci_enseignant_id)->first();

        if ($enseignant === null) {
            return ['enseignant' => null, 'enseignant_nom' => 'Enseignant #' . $evaluation->klassci_enseignant_id];
        }

        return [
            'enseignant' => ['id' => $enseignant->klassci_id, 'nom' => $enseignant->name, 'email' => $enseignant->email],
            'enseignant_nom' => $enseignant->name,
        ];
    }

    /**
     * @param  Collection<int, Evaluation>  $evaluations
     * @return Collection<int, User>
     */
    private function auteursLocaux(Collection $evaluations): Collection
    {
        $ids = $evaluations->pluck('enseignant_id')->filter()->unique()->values();

        /** @var Collection<int, User> $auteurs */
        $auteurs = $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $auteurs;
    }

    /**
     * Sans auteur, ou un auteur d'une autre école, rien. Le bornage est fait en
     * mémoire : le préchargement couvre toutes les écoles de la liste, et le
     * scope global est fail-open sans tenant.
     *
     * @param  Collection<int, User>  $auteurs
     * @return array{enseignant: array<string, mixed>|null, enseignant_nom: string|null}
     */
    private static function auteurLocal(Evaluation $evaluation, Collection $auteurs): array
    {
        $candidat = $evaluation->enseignant_id === null ? null : $auteurs->get($evaluation->enseignant_id);
        $auteur = $candidat instanceof User && $evaluation->institution_id !== null
            && (int) $candidat->institution_id === (int) $evaluation->institution_id ? $candidat : null;

        return [
            'enseignant' => $auteur === null ? null : ['id' => $auteur->id, 'nom' => $auteur->name, 'email' => $auteur->email],
            'enseignant_nom' => $auteur?->name,
        ];
    }
}
