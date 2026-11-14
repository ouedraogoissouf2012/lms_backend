<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;
use App\Support\Catalogue\SourceLocale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Le détail d'une séance d'une école créée depuis le LMS, sans aucun appel à
 * KLASSCI, même pour un compte porteur d'un jeton.
 *
 * - La séance est désignée par son id LOCAL, sous la règle de la liste
 *   ({@see SeancesLocalesVisibles}) : le détail ne montre jamais ce que la liste
 *   cache (404, comme une séance inexistante).
 * - `seance` : sa forme de calendrier (classe, matière, auteur comme enseignant,
 *   horaires avec `heure_fin_estimee`).
 * - `visio` : la fenêtre se calcule sur le début et la fin ESTIMÉE ; pas de
 *   fenêtre sans date.
 * - `participants` : pour le personnel seulement, comme dans l'école KLASSCI ;
 *   la règle de `GET …/participants` ({@see ParticipantsDeSeanceLocale}).
 */
final class LocalSeanceDetailReader implements SeanceDetailReader
{
    public function __construct(
        private readonly SeancesLocalesVisibles $visibles,
        private readonly SeanceVisioEnricher $visio,
        private readonly ParticipantsDeSeanceLocale $participantsLocaux,
    ) {}

    public function detailFor(int $seanceId, User $user): ?array
    {
        $institution = $user->institution_id;
        if (! is_int($institution)) {
            return null;
        }

        $ligne = $this->visibles->pour($user, $institution)
            ->withConnectedParticipantsCount()
            ->whereKey($seanceId)
            ->first();

        if (! $ligne instanceof Seance) {
            return null;
        }

        $seance = $this->visibles->projeter(new Collection([$ligne]), $institution)[0];
        $data = ['seance' => $seance, 'visio' => $this->visio($ligne, $seance)];

        if ($user->isStaff()) {
            $data['participants'] = $this->participants($ligne);
        }

        return ['data' => $data, 'meta' => ['source' => SourceLocale::LMS_LOCAL]];
    }

    /**
     * @param  array<string, mixed>  $seance
     * @return array<string, mixed>
     */
    private function visio(Seance $ligne, array $seance): array
    {
        $debut = $ligne->date_seance ? Carbon::parse($ligne->date_seance) : null;

        return [
            'enabled' => $ligne->visio_enabled,
            'type' => $ligne->visio_type,
            'room_id' => $ligne->visio_room_id,
            'status' => $ligne->visio_status,
            'window' => $debut === null ? null : $this->visio->buildVisioWindow(
                $debut->copy(),
                $debut->copy()->addHours(SeanceCalendarProjection::DUREE_SUPPOSEE_HEURES),
                $seance,
                $ligne,
            ),
        ];
    }

    /**
     * @return array{teacher: array{id: int, nom: string}|null, students: list<array{id: int, nom: string}>, total: int}
     */
    private function participants(Seance $ligne): array
    {
        $lus = $this->participantsLocaux->de($ligne);

        return [
            'teacher' => $lus['auteur'] === null ? null : ['id' => (int) $lus['auteur']->id, 'nom' => (string) $lus['auteur']->name],
            'students' => array_map(static fn (User $eleve): array => ['id' => (int) $eleve->id, 'nom' => (string) $eleve->name], $lus['eleves']),
            'total' => $lus['total'],
        ];
    }
}
