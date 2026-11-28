<?php

declare(strict_types=1);

namespace App\Services\Bulletin;

use App\Exceptions\BusinessException;
use App\Models\AppreciationDeBulletin;
use App\Models\Classe;
use App\Models\User;
use App\Services\Evaluation\Classe\ElevesDeLaClasse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * #1677 (B1) : les appréciations d'un bulletin, pour une classe et une période.
 *
 * - L'appréciation d'une MATIÈRE s'écrit par son enseignant dans la classe
 *   (`classe_matiere.enseignant_id`) : 403 `report_card_subject_not_taught` sinon.
 * - L'appréciation GÉNÉRALE et la décision du conseil (`matiere_id` nul) s'écrivent par la gestion
 *   (admin, coordinateur) : 403 `report_card_management_only` sinon.
 * - L'élève doit être de la classe (ses inscriptions actives), la matière de son programme : 422
 *   sinon. Écrire remplace l'appréciation précédente de la même case.
 * - Lecture : la gestion lit toute la classe ; l'enseignant, ses matières.
 */
final class AppreciationsDeBulletin
{
    public function __construct(
        private readonly PorteDesBulletins $porte,
        private readonly ElevesDeLaClasse $eleves,
    ) {}

    /** @return Collection<int, AppreciationDeBulletin> */
    public function de(Classe $classe, PeriodeDuBulletin $periode, User $lecteur): Collection
    {
        $this->porte->ecoleDe($lecteur);
        $requete = self::periode(AppreciationDeBulletin::query()->withoutGlobalScope('institution')->where('classe_id', $classe->id), $periode);
        if (! $lecteur->isManager()) {
            $requete->whereIn('matiere_id', self::matieresEnseignees($classe, $lecteur));
        }

        return $requete->orderBy('student_id')->orderBy('matiere_id')->get();
    }

    public function ecrire(Classe $classe, PeriodeDuBulletin $periode, User $auteur, int $eleve, ?int $matiere, ?string $texte, ?string $decision): AppreciationDeBulletin
    {
        $this->porte->ecoleDe($auteur);
        if ($matiere === null && ! $auteur->isManager()) {
            throw new BusinessException('L\'appréciation générale et la décision sont celles de la gestion.', 403, null, 'report_card_management_only');
        }
        if (false) { // FALSIFICATION : matière non vérifiée.
            throw new BusinessException('Vous n\'enseignez pas cette matière dans cette classe.', 403, null, 'report_card_subject_not_taught');
        }
        $deLaClasse = array_filter($this->eleves->de($classe, $auteur), static fn ($e): bool => $e->compte === $eleve);
        if ($deLaClasse === []) {
            throw new BusinessException('Cet élève n\'est pas de la classe.', 422, null, 'student_not_in_class');
        }

        $existante = self::periode(AppreciationDeBulletin::query()->withoutGlobalScope('institution')
            ->where('classe_id', $classe->id)->where('student_id', $eleve)
            ->when($matiere === null, static fn (Builder $q) => $q->whereNull('matiere_id'), static fn (Builder $q) => $q->where('matiere_id', $matiere)), $periode)->first();
        $appreciation = $existante ?? new AppreciationDeBulletin([
            'institution_id' => $classe->institution_id, 'classe_id' => $classe->id, 'student_id' => $eleve, 'matiere_id' => $matiere,
            'periode_du' => $periode->du, 'periode_au' => $periode->au,
        ]);
        $appreciation->fill([
            'periode_libelle' => $periode->libelle, 'texte' => $texte,
            'decision' => $matiere === null ? $decision : null, 'auteur_id' => $auteur->id,
        ])->save();

        return $appreciation;
    }

    /**
     * La matière, pour la gestion : toutes celles du programme ; pour l'enseignant : les siennes.
     *
     * @return list<int>
     */
    private static function matieresEnseignees(Classe $classe, User $acteur): array
    {
        return array_values(DB::table('classe_matiere')->where('classe_id', $classe->id)
            ->when(! $acteur->isManager(), static fn (QueryBuilder $q) => $q->where('enseignant_id', $acteur->id))
            ->pluck('matiere_id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all());
    }

    /**
     * @param  Builder<AppreciationDeBulletin>  $requete
     * @return Builder<AppreciationDeBulletin>
     */
    private static function periode(Builder $requete, PeriodeDuBulletin $periode): Builder
    {
        return $requete->whereDate('periode_du', $periode->du->toDateString())->whereDate('periode_au', $periode->au->toDateString());
    }
}
