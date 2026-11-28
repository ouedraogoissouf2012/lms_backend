<?php

declare(strict_types=1);

namespace App\Services\Attendances\Justification;

use App\Enums\AttendanceMark;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use App\Services\Attendances\PresenceRetenue;
use App\Services\Classe\ClassesAtteintes;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Ce qu'un élève peut justifier (#1681, ARRÊT 1 Q2) : une séance PASSÉE d'une de ses classes ACTIVES où sa présence
 * retenue ({@see PresenceRetenue}) n'est pas « présent ». Un retard est une présence ; une absence déjà excusée ne
 * se justifie plus. C'est la SEULE règle : la liste « Mes absences » et le dépôt la lisent.
 *
 * Toute lecture est bornée EXPLICITEMENT à l'école de l'élève.
 */
final class AbsencesDeLEleve
{
    public const NON_COMMENCEE = 'seance_not_started';

    public const NON_JUSTIFIABLE = 'seance_not_justifiable';

    public const AUCUNE_ABSENCE = 'no_absence_in_range';

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @return list<array{seance_id: int, titre: ?string, date_seance: ?string, marque: ?string, justificatif_etat: ?string}> */
    public function absences(User $eleve): array
    {
        $seances = $this->seancesDeSesClasses($eleve)->where('date_seance', '<=', now())->orderByDesc('date_seance')->get();
        $lignes = $this->lignes($eleve, $seances);
        $absentes = $this->sansPresence($seances, $lignes);
        $etats = $this->etatsDesJustificatifs($eleve, array_map(static fn (Seance $s): int => (int) $s->id, $absentes));

        return array_map(static fn (Seance $s): array => [
            'seance_id' => (int) $s->id,
            'titre' => $s->titre,
            'date_seance' => $s->date_seance === null ? null : Carbon::parse($s->date_seance)->toIso8601String(),
            'marque' => isset($lignes[$s->id]) ? PresenceRetenue::de($lignes[$s->id])?->value : null,
            'justificatif_etat' => $etats[$s->id] ?? null,
        ], $absentes);
    }

    /**
     * Les séances visées par un dépôt : nommées (chacune doit être justifiable) ou une plage (ses séances justifiables).
     *
     * @param  ?list<int>  $ids
     * @return list<int>
     *
     * @throws BusinessException 404 hors de ses classes, 409 à venir, 422 non justifiable ou plage sans absence
     */
    public function visees(User $eleve, ?array $ids, ?string $du, ?string $au): array
    {
        if ($ids === null) {
            $seances = $this->seancesDeSesClasses($eleve)
                ->whereBetween('date_seance', [Carbon::parse((string) $du)->startOfDay(), Carbon::parse((string) $au)->endOfDay()])
                ->where('date_seance', '<=', now())->get();
            $justifiables = $this->sansPresence($seances, $this->lignes($eleve, $seances));
            if ($justifiables === []) {
                throw new BusinessException('Aucune absence à justifier sur ces dates.', 422, null, self::AUCUNE_ABSENCE);
            }

            return array_map(static fn (Seance $s): int => (int) $s->id, $justifiables);
        }

        return $this->nommees($eleve, $ids);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function nommees(User $eleve, array $ids): array
    {
        $seances = $this->seancesDeSesClasses($eleve)->whereKey($ids)->get();
        if ($seances->count() !== count($ids)) {
            throw new BusinessException('Séance non trouvée', 404);
        }
        if ($seances->contains(static fn (Seance $s): bool => $s->date_seance === null || Carbon::parse($s->date_seance)->isAfter(now()))) {
            throw new BusinessException('Une séance à venir ne se justifie pas encore.', 409, null, self::NON_COMMENCEE);
        }
        if (count($this->sansPresence($seances, $this->lignes($eleve, $seances))) !== count($ids)) {
            throw new BusinessException('Cette séance n\'est pas une absence à justifier.', 422, null, self::NON_JUSTIFIABLE);
        }

        return $ids;
    }

    /**
     * Les séances des classes où l'élève est inscrit, inscription ACTIVE, dans son école.
     *
     * @return Builder<Seance>
     */
    private function seancesDeSesClasses(User $eleve): Builder
    {
        $ecole = (int) $eleve->institution_id;
        $classes = ClassesAtteintes::inscrites(Classe::query()->withoutGlobalScope('institution')->where('institution_id', $ecole), $eleve)
            ->select('classes.id');

        return Seance::query()->withoutGlobalScope('institution')
            ->where('institution_id', $ecole)
            ->where('is_active', true)
            ->whereIn('classe_id', $classes);
    }

    /**
     * @param  Collection<int, Seance>  $seances
     * @param  array<int, ESBTPAttendance>  $lignes  par séance
     * @return list<Seance>
     */
    private function sansPresence(Collection $seances, array $lignes): array
    {
        return array_values($seances->filter(static function (Seance $s) use ($lignes): bool {
            $ligne = $lignes[$s->id] ?? null;

            return $ligne === null || (! PresenceRetenue::estPresente($ligne) && $ligne->mark !== AttendanceMark::Excused);
        })->all());
    }

    /**
     * @param  Collection<int, Seance>  $seances
     * @return array<int, ESBTPAttendance>
     */
    private function lignes(User $eleve, Collection $seances): array
    {
        return ESBTPAttendance::query()->withoutGlobalScope('institution')
            ->where('institution_id', $eleve->institution_id)
            ->where('user_id', $eleve->id)
            ->whereIn('seance_id', $seances->modelKeys())
            ->get()->keyBy('seance_id')->all();
    }

    /**
     * L'état du justificatif le plus récent de l'élève qui vise chaque séance.
     *
     * @param  list<int>  $seanceIds
     * @return array<int, string>
     */
    private function etatsDesJustificatifs(User $eleve, array $seanceIds): array
    {
        $etats = [];
        $lignes = $this->db->table('justificatif_absence_seance as v')
            ->join('justificatifs_absence as j', 'j.id', '=', 'v.justificatif_id')
            ->where('j.institution_id', $eleve->institution_id)
            ->where('j.eleve_id', $eleve->id)
            ->whereIn('v.seance_id', $seanceIds)
            ->orderBy('j.id')
            ->get(['v.seance_id', 'j.etat']);
        foreach ($lignes as $ligne) {
            $etats[(int) $ligne->seance_id] = (string) $ligne->etat;
        }

        return $etats;
    }
}
