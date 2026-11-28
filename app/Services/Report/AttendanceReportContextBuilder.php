<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\AttendanceMark;
use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Services\Attendances\PresenceRetenue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Le contexte du rapport de présences d'une période, pour l'école ou pour UNE classe.
 *
 * ## Une classe, deux identités, chacune dans son espace (#1709)
 *
 * Une séance créée dans le LMS désigne sa classe par `seances.classe_id`, l'id LOCAL
 * (#846). Une séance miroir de KLASSCI la désigne par `seances.klassci_classe_id`, qui
 * vaut le `klassci_id` de la classe miroir. Le filtre lit chacune dans son espace : un id
 * local n'est jamais comparé à un id KLASSCI. Aucun test du mode : c'est la donnée qui
 * porte l'identité, et une classe sans `klassci_id` n'a que la première.
 *
 * ## La classe est cherchée dans l'établissement de l'acteur
 *
 * Explicitement, et pas seulement par le scope de l'école : le job du rapport asynchrone
 * repose ce scope (#536), mais un filtre qui en dépendrait seul disparaîtrait avec lui.
 * Une classe introuvable dans l'établissement fait échouer le rapport : jamais un repli
 * sur l'école entière, qui se lirait comme le rapport de la classe.
 */
final class AttendanceReportContextBuilder
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function build(array $data, ?int $institutionId): array
    {
        $dateStart = Carbon::parse($this->stringField($data, 'date_start'));
        $dateEnd = Carbon::parse($this->stringField($data, 'date_end'));
        $seances = $this->seances($dateStart, $dateEnd, $this->integerField($data, 'classe_id'), $institutionId);

        $attendances = ESBTPAttendance::query()
            ->whereIn('seance_id', $seances->select('id'))
            ->with(['user', 'seance'])
            ->get();
        $ecole = $this->compte($attendances);

        $students = $attendances->groupBy('user_id')->map(function (Collection $items): array {
            $student = $items->first()?->user;
            $compte = $this->compte($items);

            return [
                'name' => $student->name ?? 'Inconnu',
                'email' => $student->email ?? '',
                'total' => $compte['total'],
                'presents' => $compte['presents'],
                'absents' => $compte['absents'],
                'retards' => $compte['retards'],
                'taux' => $compte['total'] > 0 ? round(($compte['presents'] / $compte['total']) * 100, 2) : 0.0,
            ];
        })->values();

        return [
            'title' => 'Rapport de Présences',
            'date_start' => $dateStart->format('d/m/Y'),
            'date_end' => $dateEnd->format('d/m/Y'),
            'generated_at' => Carbon::now()->format('d/m/Y H:i'),
            'stats' => $ecole + [
                'taux_presence' => $ecole['total'] > 0 ? round(($ecole['presents'] / $ecole['total']) * 100, 2) : 0.0,
            ],
            'students' => $students,
        ];
    }

    /**
     * @return Builder<Seance>
     */
    private function seances(Carbon $dateStart, Carbon $dateEnd, ?int $classeId, ?int $institutionId): Builder
    {
        $seances = Seance::query()
            ->whereBetween('date_seance', [$dateStart, $dateEnd->copy()->endOfDay()]);

        if ($classeId === null) {
            return $seances;
        }

        $classe = $this->classeDeLEtablissement($classeId, $institutionId);
        $klassciId = $classe->klassci_id;

        return $seances
            ->where('institution_id', $institutionId)
            ->where(function (Builder $deLaClasse) use ($classe, $klassciId): void {
                $deLaClasse->where('classe_id', $classe->getKey());
                if (is_numeric($klassciId)) {
                    $deLaClasse->orWhere('klassci_classe_id', (int) $klassciId);
                }
            });
    }

    /**
     * @throws ModelNotFoundException<Classe> hors de l'établissement, ou sans établissement
     */
    private function classeDeLEtablissement(int $classeId, ?int $institutionId): Classe
    {
        if ($institutionId === null) {
            throw (new ModelNotFoundException)->setModel(Classe::class, [$classeId]);
        }

        return Classe::query()->withoutGlobalScope('institution')
            ->where('institution_id', $institutionId)
            ->whereKey($classeId)
            ->firstOrFail(['id', 'klassci_id']);
    }

    /**
     * Ce que comptent des lignes, par LA règle ({@see PresenceRetenue}, #1649) : la marque de l'appel l'emporte sur
     * le statut visio ; un statut nul n'est jamais présent. Un retard compte présent ET retard.
     *
     * @param  Collection<int, ESBTPAttendance>  $lignes
     * @return array{total: int, presents: int, absents: int, retards: int}
     */
    private function compte(Collection $lignes): array
    {
        $retenues = $lignes->map(static fn (ESBTPAttendance $l): ?AttendanceMark => PresenceRetenue::de($l));

        return [
            'total' => $lignes->count(),
            'presents' => $lignes->filter(static fn (ESBTPAttendance $l): bool => PresenceRetenue::estPresente($l))->count(),
            'absents' => $retenues->filter(static fn (?AttendanceMark $m): bool => $m === AttendanceMark::Absent)->count(),
            'retards' => $retenues->filter(static fn (?AttendanceMark $m): bool => $m === AttendanceMark::Late)->count(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $data */
    private function integerField(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
