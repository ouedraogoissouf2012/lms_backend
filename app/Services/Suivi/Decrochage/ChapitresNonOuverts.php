<?php

declare(strict_types=1);

namespace App\Services\Suivi\Decrochage;

use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * #1704, règle « chapitres non ouverts » : la part des chapitres de la classe que l'élève n'a
 * jamais ouverts.
 *
 * - Les chapitres : ceux des leçons PUBLIÉES de la classe (`lessons.classe_id`, la règle de lecture
 *   des élèves, `LessonReadScope`), publiées depuis au moins 7 jours : un chapitre d'hier n'est pas
 *   encore un retard. Un chapitre ou une leçon supprimés ne comptent pas.
 * - Ouvert : une ligne `chapter_progress` existe pour l'élève. Elle n'est écrite qu'au premier
 *   temps enregistré sur le chapitre, ou à sa fin (`ChapterTimeRecorder`) : un chapitre affiché
 *   sans aucun temps enregistré compte comme non ouvert (déclaré).
 */
final class ChapitresNonOuverts
{
    public const DELAI_JOURS = 7;

    /**
     * @param  Collection<int, User>  $eleves
     * @return array{total: int, ouverts: array<int, int>} par compte, le nombre de chapitres ouverts
     */
    public function de(Classe $classe, Collection $eleves, CarbonInterface $maintenant): array
    {
        $institutionId = (int) $classe->institution_id;
        $publieesAvant = $maintenant->copy()->subDays(self::DELAI_JOURS);

        $lecons = Lesson::query()->withoutGlobalScope('institution')
            ->where('institution_id', $institutionId)
            ->where('classe_id', $classe->id)
            ->published()
            ->where('published_at', '<=', $publieesAvant)
            ->pluck('id')
            ->all();
        $chapitres = $lecons === [] ? [] : Chapter::query()->withoutGlobalScope('institution')
            ->where('institution_id', $institutionId)
            ->whereIn('lesson_id', $lecons)
            ->pluck('id')
            ->all();

        if ($chapitres === [] || $eleves->isEmpty()) {
            return ['total' => count($chapitres), 'ouverts' => []];
        }

        // Les chapitres et les élèves sont déjà ceux de l'école : la ligne de progression n'a pas
        // à porter son `institution_id`, qu'une ligne ancienne peut ne pas avoir.
        /** @var array<int, int> $ouverts */
        $ouverts = DB::table('chapter_progress')
            ->whereIn('chapter_id', $chapitres)
            ->whereIn('user_id', $eleves->map(static fn (User $eleve): int => (int) $eleve->id)->all())
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(DISTINCT chapter_id) AS ouverts')
            ->pluck('ouverts', 'user_id')
            ->map(static fn (mixed $n): int => is_numeric($n) ? (int) $n : 0)
            ->all();

        return ['total' => count($chapitres), 'ouverts' => $ouverts];
    }
}
