<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\User;
use App\Services\Enseignant\IdDansLAnnuaire;
use Illuminate\Support\Collection;

/**
 * L'enseignant d'une évaluation présentée ({@see EvaluationEnrichmentService}).
 *
 * Sorti de l'enrichissement quand celui-ci dépassait la garde de taille (revue
 * d'E3), sans changement de comportement :
 * - identité KLASSCI (`klassci_enseignant_id`) : le compte de l'établissement qui
 *   porte cette identité d'ENSEIGNANT (#873 : pas le numéro de compte `klassci_id`) ;
 * - sinon, l'auteur local (`enseignant_id`, D72), borné à l'établissement de
 *   l'évaluation, et rendu par son id DANS L'ESPACE DE L'ANNUAIRE de son école
 *   ({@see IdDansLAnnuaire}, #1537) : l'id local en école autonome, son identité
 *   d'enseignant KLASSCI en école KLASSCI. Hors annuaire (sans identité, ou qui n'est
 *   pas enseignant), `enseignant` est nul et le nom reste dans `enseignant_nom` : la
 *   spec ne permet pas un `enseignant.id` nul.
 *
 * Les auteurs locaux sont préchargés en UNE requête pour toute la liste, et non
 * une par évaluation : la liste du personnel en rend jusqu'à 100. L'id dans
 * l'annuaire est résolu une fois par auteur.
 */
final class EnseignantsDesEvaluations
{
    public function __construct(
        private readonly IdDansLAnnuaire $idDansLAnnuaire,
    ) {}

    /**
     * @param  Collection<int, Evaluation>  $evaluations
     * @return \Closure(Evaluation): array{enseignant: array<string, mixed>|null, enseignant_nom: string|null}
     */
    public function de(Collection $evaluations): \Closure
    {
        $auteurs = $this->auteursLocaux($evaluations);
        /** @var array<int, int|null> $ids L'id dans l'annuaire, une fois par auteur. */
        $ids = [];

        return function (Evaluation $evaluation) use ($auteurs, &$ids): array {
            if ($evaluation->klassci_enseignant_id) {
                return $this->parIdentiteKlassci($evaluation);
            }

            $auteur = self::auteurLocal($evaluation, $auteurs);
            if ($auteur === null) {
                return ['enseignant' => null, 'enseignant_nom' => null];
            }
            if (! array_key_exists($auteur->id, $ids)) {
                $ids[$auteur->id] = $this->idDansLAnnuaire->de($auteur);
            }

            return [
                'enseignant' => $ids[$auteur->id] === null ? null : ['id' => $ids[$auteur->id], 'nom' => $auteur->name, 'email' => $auteur->email],
                'enseignant_nom' => $ids[$auteur->id] === null ? null : $auteur->name,
            ];
        };
    }

    /**
     * @return array{enseignant: array<string, mixed>|null, enseignant_nom: string|null}
     */
    private function parIdentiteKlassci(Evaluation $evaluation): array
    {
        // #873 : dans l'espace des ENSEIGNANTS KLASSCI, celui de la colonne lue
        // (`KlassciEnseignantIdResolver`), et dans l'école de l'évaluation. `klassci_id` est le
        // numéro de COMPTE KLASSCI, un autre espace : le même nombre y désigne un autre compte.
        $enseignant = $evaluation->institution_id === null ? null : User::query()->withoutGlobalScope('institution')
            ->where('institution_id', $evaluation->institution_id)
            ->where('klassci_enseignant_id', $evaluation->klassci_enseignant_id)
            ->first();

        if ($enseignant === null) {
            return ['enseignant' => null, 'enseignant_nom' => 'Enseignant #'.$evaluation->klassci_enseignant_id];
        }

        return [
            // L'identité par laquelle il a été trouvé, celle de l'évaluation : l'espace des
            // enseignants KLASSCI, jamais nul ici. `klassci_id` (un autre espace) l'était
            // pour un enseignant qui n'a que son identité d'enseignant (#873).
            'enseignant' => ['id' => $enseignant->klassci_enseignant_id, 'nom' => $enseignant->name, 'email' => $enseignant->email],
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
     */
    private static function auteurLocal(Evaluation $evaluation, Collection $auteurs): ?User
    {
        $candidat = $evaluation->enseignant_id === null ? null : $auteurs->get($evaluation->enseignant_id);

        return $candidat instanceof User && $evaluation->institution_id !== null
            && (int) $candidat->institution_id === (int) $evaluation->institution_id ? $candidat : null;
    }
}
