<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Classe;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * École autonome : les classes d'une matière dans le programme LOCAL
 * (`classe_matiere`), celles que la page de la matière propose
 * ({@see LocalMatiereDetailsReader}, qui lit cette règle ici).
 *
 * | Appelant | Classes |
 * |---|---|
 * | gestion (coordinateur, admin) | toutes celles qui ont la matière au programme |
 * | enseignant | celles où il est formateur désigné de la matière |
 * | apprenant | celles de ce programme où son inscription est ACTIVE |
 */
final class LocalClassesDeLaMatiere implements ClassesDeLaMatiere
{
    public function idsPour(Matiere $matiere, User $compte): array
    {
        $institution = $compte->institution_id;
        if (! is_int($institution) || (int) $matiere->institution_id !== $institution) {
            return [];
        }

        return array_map(static fn (Classe $classe): int => $classe->id, $this->visibles($matiere, $compte, $institution) ?? []);
    }

    /**
     * Les classes de cette matière que l'appelant atteint, ou `null` s'il
     * n'atteint pas la matière. La gestion l'atteint même sans classe : une
     * matière au catalogue, pas encore au programme, reste à elle.
     *
     * @return list<Classe>|null
     */
    public function visibles(Matiere $matiere, User $user, int $institution): ?array
    {
        $classes = $this->programme($matiere, $user->isTeacher() ? $user->id : null, $institution)
            ->orderBy('libelle')
            ->orderBy('id');

        if (self::apprenant($user)) {
            // Portée d'apprenant, celle de tout rôle non reconnu (#941) : une
            // inscription ACTIVE, et rien d'autre (D57).
            $classes->whereHas('etudiants', static function ($inscrits) use ($user): void {
                $inscrits->where('users.id', $user->id)->where('classe_etudiant.statut', 'actif');
            });
        }

        $visibles = array_values($classes->get()->all());

        return $visibles === [] && ! $user->isManager() ? null : $visibles;
    }

    /**
     * Les classes de l'établissement qui ont cette matière au programme ; pour un
     * formateur désigné (`$designe`), celles où il l'enseigne.
     *
     * @return Builder<Classe>
     */
    public function programme(Matiere $matiere, ?int $designe, int $institution): Builder
    {
        return Classe::query()
            ->where('institution_id', $institution)
            ->whereHas('matieres', static function ($programme) use ($matiere, $designe): void {
                $programme->where('matieres.id', $matiere->id);

                if ($designe !== null) {
                    $programme->where('classe_matiere.enseignant_id', $designe);
                }
            });
    }

    /** Portée d'apprenant, celle de tout rôle non reconnu (#941). */
    public static function apprenant(User $user): bool
    {
        return ! $user->isManager() && ! $user->isTeacher();
    }
}
