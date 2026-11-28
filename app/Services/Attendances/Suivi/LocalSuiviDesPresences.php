<?php

declare(strict_types=1);

namespace App\Services\Attendances\Suivi;

use App\Enums\ClasseEtudiantStatut;
use App\Models\User;
use App\Services\Attendances\PresenceRetenue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;

/**
 * École créée depuis le LMS : le suivi des présences (#1683). Une présence est une paire (séance PASSÉE et active de
 * l'école × élève ACTIF de sa classe, aujourd'hui) ; sa marque est celle de {@see PresenceRetenue::expression()} :
 * sans ligne, ou sans rien qui dise une présence, l'élève est absent. Le taux vient de {@see TauxDePresence}.
 *
 * Toute jointure est bornée EXPLICITEMENT à l'école du lecteur : aucune ne dépend du scope global. Limite déclarée :
 * l'élève attendu est celui dont l'inscription est active aujourd'hui (aucun historique d'inscription n'est tenu).
 */
final class LocalSuiviDesPresences implements SuiviDesPresences
{
    public const PAR_PAGE = 50;

    public function __construct(private readonly ConnectionInterface $db) {}

    public function tenuDansLeLms(): bool
    {
        return true;
    }

    public function lister(User $gestion, FiltresDePresences $filtres): array
    {
        $marque = PresenceRetenue::expression('a.mark', 'a.status');
        $page = $this->paires($gestion, $filtres)
            ->when($filtres->marque !== null, static fn (Builder $q) => $q->whereRaw("{$marque} = ?", [$filtres->marque?->value]))
            ->selectRaw("s.id as seance_id, s.date_seance, s.classe_id, s.matiere_id, u.id as eleve_id, u.name as eleve_nom, {$marque} as marque")
            ->orderBy('s.date_seance')->orderBy('s.id')->orderBy('u.id')
            ->paginate(self::PAR_PAGE, ['*'], 'page', $filtres->page);

        $presences = [];
        foreach ($page->items() as $brute) {
            $l = is_object($brute) ? get_object_vars($brute) : [];
            $date = LigneLue::texte($l, 'date_seance');
            $presences[] = [
                'seance_id' => LigneLue::entier($l, 'seance_id'),
                'date_seance' => $date === null ? null : Carbon::parse($date)->toIso8601String(),
                'classe_id' => LigneLue::entier($l, 'classe_id'),
                'matiere_id' => LigneLue::entierOuNull($l, 'matiere_id'),
                'eleve_id' => LigneLue::entier($l, 'eleve_id'),
                'eleve_nom' => LigneLue::texte($l, 'eleve_nom'),
                'marque' => (string) LigneLue::texte($l, 'marque'),
            ];
        }

        return [
            'presences' => $presences,
            'page' => $page->currentPage(),
            'derniere_page' => $page->lastPage(),
            'total' => $page->total(),
        ];
    }

    public function synthese(User $gestion, FiltresDePresences $filtres, int $seuil): array
    {
        $marque = PresenceRetenue::expression('a.mark', 'a.status');
        $compte = static fn (string $valeurs): string => "SUM(CASE WHEN {$marque} IN ({$valeurs}) THEN 1 ELSE 0 END)";
        $requete = $this->paires($gestion, $filtres)
            ->join('classes as c', 'c.id', '=', 's.classe_id')
            ->groupBy('u.id', 'u.name', 's.classe_id', 'c.libelle')
            ->selectRaw('u.id as eleve_id, u.name as nom, s.classe_id, c.libelle as classe')
            ->selectRaw($compte("'present', 'late'").' as presences')
            ->selectRaw($compte("'late'").' as retards')
            ->selectRaw($compte("'excused'").' as justifiees')
            ->selectRaw($compte("'absent'").' as absences')
            ->orderBy('u.id')->orderBy('s.classe_id');
        $lignes = [];
        foreach ($requete->get() as $brute) {
            $lignes[] = get_object_vars($brute);
        }

        $eleves = SyntheseDesPresences::parEleve($lignes);

        return [
            'du' => $filtres->du,
            'au' => $filtres->au,
            'seuil' => $seuil,
            'eleves' => $eleves,
            'classes' => SyntheseDesPresences::parClasse($lignes),
            'sous_le_seuil' => SyntheseDesPresences::sousLeSeuil($eleves, $seuil),
        ];
    }

    /** Les paires (séance passée × élève actif de sa classe) de l'école, filtrées, avec leur ligne éventuelle. */
    private function paires(User $gestion, FiltresDePresences $filtres): Builder
    {
        $ecole = (int) $gestion->institution_id;
        $fin = Carbon::parse($filtres->au)->endOfDay()->min(now());

        return $this->db->table('seances as s')
            ->join('classe_etudiant as ce', static fn (JoinClause $j) => $j->on('ce.classe_id', '=', 's.classe_id')
                ->where('ce.institution_id', $ecole)->where('ce.statut', ClasseEtudiantStatut::Actif->value))
            ->join('users as u', static fn (JoinClause $j) => $j->on('u.id', '=', 'ce.user_id')->where('u.institution_id', $ecole))
            ->leftJoin('esbtp_attendance as a', static fn (JoinClause $j) => $j->on('a.seance_id', '=', 's.id')
                ->on('a.user_id', '=', 'u.id')->where('a.institution_id', $ecole))
            ->where('s.institution_id', $ecole)
            ->where('s.is_active', true)
            ->whereBetween('s.date_seance', [Carbon::parse($filtres->du)->startOfDay(), $fin])
            ->when($filtres->classeId !== null, static fn (Builder $q) => $q->where('s.classe_id', $filtres->classeId))
            ->when($filtres->matiereId !== null, static fn (Builder $q) => $q->where('s.matiere_id', $filtres->matiereId))
            ->when($filtres->eleveId !== null, static fn (Builder $q) => $q->where('u.id', $filtres->eleveId));
    }
}
