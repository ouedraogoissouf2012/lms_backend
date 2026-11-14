<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\Seance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class VisioActorAuthorization
{
    public const NOT_AUTHORIZED = 'actor_not_authorized';

    /**
     * Le refus rendu quand {@see canManage()} dit non : une seule forme pour les
     * routes qui gèrent une visio.
     *
     * @return array{success: false, message: string, reason: string}
     */
    public static function managementRefusal(): array
    {
        return [
            'success' => false,
            'message' => 'Vous ne pouvez pas gérer cette visio',
            'reason' => self::NOT_AUTHORIZED,
        ];
    }

    public function canManage(Seance $seance, User $actor): bool
    {
        if ($seance->institution_id !== $actor->institution_id) {
            return false;
        }

        return $actor->isManager() || $this->teacherOwns($seance, $actor);
    }

    public function canValidate(Seance $seance, User $actor, User $target): bool
    {
        return $this->canManage($seance, $actor)
            || ($actor->isStudent() && $actor->is($target));
    }

    public function teacherOwns(Seance $seance, User $teacher): bool
    {
        if (! $teacher->isTeacher()) {
            return false;
        }

        if ($seance->klassci_enseignant_id !== null) {
            $ownerId = (int) $seance->klassci_enseignant_id;

            return (is_numeric($teacher->klassci_enseignant_id)
                    && (int) $teacher->klassci_enseignant_id === $ownerId)
                || (is_numeric($teacher->klassci_id) && (int) $teacher->klassci_id === $ownerId);
        }

        return $seance->created_by !== null
            && (int) $seance->created_by === (int) $teacher->id;
    }

    /**
     * La forme REQUÊTE de la même règle : les séances que `$teacher` gère, comme
     * {@see canManage()} le dit d'une séance (son établissement, puis
     * {@see teacherOwns()}). Une seule règle, deux formes, liées par un test
     * d'équivalence (patron de `TeacherOwnershipScope` : `applyToLessons` et
     * `ownsLesson`). Sans établissement, aucune séance.
     *
     * @param  Builder<Seance>  $seances
     */
    public function limitToTeacherSeances(Builder $seances, User $teacher): void
    {
        if (! is_int($teacher->institution_id)) {
            $seances->whereRaw('1 = 0');

            return;
        }

        $identitesKlassci = [];
        foreach ([$teacher->klassci_enseignant_id, $teacher->klassci_id] as $id) {
            if (is_numeric($id)) {
                $identitesKlassci[] = (int) $id;
            }
        }

        $seances->where($seances->qualifyColumn('institution_id'), $teacher->institution_id)
            ->where(static function (Builder $proprietaire) use ($identitesKlassci, $teacher, $seances): void {
                if ($identitesKlassci !== []) {
                    $proprietaire->whereIn($seances->qualifyColumn('klassci_enseignant_id'), $identitesKlassci);
                }
                $proprietaire->orWhere(static fn (Builder $locale): Builder => $locale
                    ->whereNull($seances->qualifyColumn('klassci_enseignant_id'))
                    ->where($seances->qualifyColumn('created_by'), $teacher->id));
            });
    }
}
