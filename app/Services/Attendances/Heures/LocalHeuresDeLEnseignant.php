<?php

declare(strict_types=1);

namespace App\Services\Attendances\Heures;

use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\VisioActorAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * École créée depuis le LMS : les heures des enseignants se tirent de la visio (#1682), par la règle unique
 * {@see HeuresEmargees}. Seules les séances PASSÉES et actives comptent ; toute lecture est bornée EXPLICITEMENT à
 * l'école du lecteur.
 */
final class LocalHeuresDeLEnseignant implements HeuresDeLEnseignant
{
    public function __construct(
        private readonly VisioActorAuthorization $acteurs,
        private readonly HeuresEmargees $heures,
    ) {}

    public function mesHeures(User $enseignant, string $mois): array
    {
        $debut = Carbon::parse($mois.'-01')->startOfMonth();
        $requete = $this->passees((int) $enseignant->institution_id, $debut, $debut->copy()->endOfMonth());
        $this->acteurs->limitToTeacherSeances($requete, $enseignant);
        $seances = $requete->orderBy('date_seance')->orderBy('id')->get();
        $lignes = array_values($seances->map(static fn (Seance $s): array => [
            'seance_id' => (int) $s->id,
            'titre' => $s->titre,
            'date_seance' => $s->date_seance === null ? null : Carbon::parse($s->date_seance)->toIso8601String(),
            'statut' => HeuresEmargees::statut($s),
            'minutes' => HeuresEmargees::minutes($s),
        ])->all());
        $comptees = count(array_filter($lignes, static fn (array $l): bool => $l['statut'] === HeuresEmargees::COMPTEE));

        return [
            'mois' => $debut->format('Y-m'),
            'depuis' => $this->heures->depuis((int) $enseignant->institution_id),
            'seances' => $lignes,
            'totaux' => [
                'minutes' => array_sum(array_column($lignes, 'minutes')),
                'comptees' => $comptees,
                'non_comptees' => count($lignes) - $comptees,
            ],
        ];
    }

    public function rapport(User $gestion, FiltresDHeures $filtres): array
    {
        $ecole = (int) $gestion->institution_id;
        $seances = $this->passees($ecole, Carbon::parse($filtres->du)->startOfDay(), Carbon::parse($filtres->au)->endOfDay())
            ->whereNotNull('created_by')
            ->when($filtres->enseignantId !== null, static fn (Builder $q) => $q->where('created_by', $filtres->enseignantId))
            ->when($filtres->classeId !== null, static fn (Builder $q) => $q->where('classe_id', $filtres->classeId))
            ->when($filtres->matiereId !== null, static fn (Builder $q) => $q->where('matiere_id', $filtres->matiereId))
            ->get();

        return [
            'du' => $filtres->du,
            'au' => $filtres->au,
            'depuis' => $this->heures->depuis($ecole),
            'enseignants' => $this->parEnseignant($seances, $ecole),
        ];
    }

    /**
     * @param  Collection<int, Seance>  $seances
     * @return list<array{enseignant_id: int, nom: ?string, seances: int, comptees: int, minutes: int}>
     */
    private function parEnseignant(Collection $seances, int $ecole): array
    {
        $noms = User::query()->withoutGlobalScope('institution')->where('institution_id', $ecole)
            ->whereIn('id', $seances->pluck('created_by')->unique()->all())->pluck('name', 'id')->all();
        $lignes = [];
        foreach ($seances->groupBy('created_by')->sortKeys() as $id => $siennes) {
            $lignes[] = [
                'enseignant_id' => (int) $id,
                'nom' => is_string($noms[$id] ?? null) ? $noms[$id] : null,
                'seances' => $siennes->count(),
                'comptees' => $siennes->filter(static fn (Seance $s): bool => HeuresEmargees::statut($s) === HeuresEmargees::COMPTEE)->count(),
                'minutes' => (int) $siennes->sum(static fn (Seance $s): int => HeuresEmargees::minutes($s)),
            ];
        }

        return $lignes;
    }

    /**
     * Les séances actives de l'école dans la plage, jusqu'à maintenant (une séance à venir ne compte rien).
     *
     * @return Builder<Seance>
     */
    private function passees(int $ecole, Carbon $debut, Carbon $fin): Builder
    {
        return Seance::query()->withoutGlobalScope('institution')
            ->where('institution_id', $ecole)
            ->where('is_active', true)
            ->whereBetween('date_seance', [$debut, $fin->min(now())]);
    }
}
