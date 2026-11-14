<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;
use App\Services\Audience\ClasseAudienceSource;
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
 *   l'auteur, et les élèves de l'audience de la séance (la règle de l'admission).
 */
final class LocalSeanceDetailReader implements SeanceDetailReader
{
    public function __construct(
        private readonly SeancesLocalesVisibles $visibles,
        private readonly SeanceVisioEnricher $visio,
        private readonly ClasseAudienceSource $audience,
    ) {}

    public function detailFor(int $seanceId, User $user): ?array
    {
        $institution = $user->institution_id;
        $ligne = ! is_int($institution) ? null : $this->visibles->pour($user, $institution)
            ->withConnectedParticipantsCount()
            ->whereKey($seanceId)
            ->first();

        if (! $ligne instanceof Seance || ! is_int($institution)) {
            return null;
        }

        $seance = $this->visibles->projeter(new Collection([$ligne]), $institution)[0];
        $data = ['seance' => $seance, 'visio' => $this->visio($ligne, $seance)];

        if ($user->isStaff()) {
            $data['participants'] = $this->participants($ligne, $seance);
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
     * @param  array<string, mixed>  $seance
     * @return array{teacher: array{id: int|null, nom: mixed}, students: list<array{id: int, nom: string}>, total: int}
     */
    private function participants(Seance $ligne, array $seance): array
    {
        $enseignant = is_array($seance['enseignant'] ?? null) ? $seance['enseignant'] : [];
        $eleves = $this->audience->studentsOf($ligne)
            ->map(static fn (User $eleve): array => ['id' => (int) $eleve->id, 'nom' => (string) $eleve->name])
            ->values()
            ->all();

        return [
            'teacher' => ['id' => $ligne->created_by, 'nom' => $enseignant['nom'] ?? null],
            'students' => $eleves,
            'total' => 1 + count($eleves),
        ];
    }
}
