<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Database\ConnectionInterface;

/**
 * SEUL écrivain du temps passé (#944, décision D2-bis du pilote my-courses).
 *
 * ## Une source, un agrégat
 *
 * Le temps réel s'enregistre par chapitre (`chapter_progress.time_spent_seconds`).
 * `lesson_progress.time_spent_minutes`, que lisent six écrans, en devient un
 * AGRÉGAT RECALCULÉ : `intdiv(somme des secondes des chapitres de la leçon, 60)`,
 * réécrit à chaque écriture du temps d'un chapitre. C'est un recalcul depuis la
 * source, jamais un second compteur : une valeur faussée redevient juste à
 * l'écriture suivante.
 *
 * Une garde d'architecture refuse toute écriture de ces deux champs hors de
 * cette classe (tests/Feature/Architecture/ChapterTimeSingleWriterTest.php).
 *
 * ## Un ordre de verrouillage unique
 *
 * Deux chapitres de la même leçon écrits en même temps : si chacun verrouillait
 * d'abord SA ligne de chapitre puis la somme des autres, InnoDB les croiserait
 * et en annulerait un (interblocage, ERROR 1213), et ce temps serait perdu.
 * Tous les écrivains d'un couple (utilisateur, leçon) verrouillent donc D'ABORD
 * la ligne parente `lesson_progress`, puis le chapitre, puis lisent la somme
 * (manuel MySQL 8.0, §17.7.5 : verrouiller dans le même ordre).
 *
 * La ligne parente n'est insérée QUE si elle manque. Un INSERT qui tombe sur un
 * doublon pose un verrou partagé sur la ligne existante (manuel MySQL 8.0,
 * §17.7.3) : deux écrivains qui le tiendraient puis demanderaient le verrou
 * exclusif s'interbloqueraient, justement dans le cas courant. Le cas rare,
 * deux toutes premières écritures simultanées, est couvert par les tentatives
 * de la transaction.
 */
final class ChapterTimeRecorder
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Ajoute `$seconds` au chapitre et recalcule l'agrégat de sa leçon.
     * `$seconds` nul : rien n'est ajouté, mais l'ordre de verrouillage et le
     * recalcul restent les mêmes. Un appelant qui écrit ensuite d'autres champs
     * du chapitre dans sa transaction garde donc l'ordre leçon puis chapitre.
     */
    public function record(int $userId, int $chapterId, int $seconds): ChapterProgress
    {
        // withTrashed : un écrivain existant ne doit pas se mettre à répondre 404
        // pour un chapitre supprimé dont la progression s'écrivait jusqu'ici.
        $chapter = Chapter::withTrashed()->select(['id', 'lesson_id'])->findOrFail($chapterId);
        $lessonId = (int) $chapter->lesson_id;

        /** @var ChapterProgress $progress */
        $progress = $this->db->transaction(function () use ($userId, $chapterId, $lessonId, $seconds): ChapterProgress {
            $parent = $this->lockLessonRow($userId, $lessonId);

            $progress = ChapterProgress::firstOrCreate(
                ['user_id' => $userId, 'chapter_id' => $chapterId],
                ['time_spent_seconds' => 0],
            );
            if ($seconds > 0) {
                $progress->increment('time_spent_seconds', $seconds);
            }

            $this->writeAggregate($parent, $userId, $lessonId);

            return $progress;
        }, self::TRANSACTION_ATTEMPTS);

        return $progress;
    }

    /**
     * Efface la progression de tous les chapitres de la leçon (remise à zéro),
     * puis remet l'agrégat en accord. Même ordre de verrouillage que record().
     */
    public function resetLesson(int $userId, int $lessonId): void
    {
        $this->db->transaction(function () use ($userId, $lessonId): void {
            $parent = $this->lockLessonRow($userId, $lessonId);

            ChapterProgress::query()
                ->where('user_id', $userId)
                ->whereIn('chapter_id', $this->chapterIdsOf($lessonId))
                ->delete();

            $this->writeAggregate($parent, $userId, $lessonId);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Recalcule l'agrégat d'un couple sans rien ajouter : commande de
     * rattrapage. Idempotent par construction. Rend les minutes écrites.
     */
    public function recalculateLesson(int $userId, int $lessonId): int
    {
        /** @var int $minutes */
        $minutes = $this->db->transaction(
            fn (): int => $this->writeAggregate($this->lockLessonRow($userId, $lessonId), $userId, $lessonId),
            self::TRANSACTION_ATTEMPTS,
        );

        return $minutes;
    }

    /**
     * Ligne parente, verrouillée. Créée aux valeurs par défaut de la base
     * (`not_started`, 0 %) si elle manque, comme le fait déjà la lecture de la
     * progression (LessonProgressController) : le statut reste la propriété de
     * LessonProgressService.
     */
    private function lockLessonRow(int $userId, int $lessonId): LessonProgress
    {
        $cle = ['user_id' => $userId, 'lesson_id' => $lessonId];

        if (! LessonProgress::query()->where($cle)->exists()) {
            // insertOrIgnore contourne le modèle : l'établissement est celui de
            // la leçon, posé explicitement (aucun tenant en ligne de commande).
            LessonProgress::query()->insertOrIgnore([$cle + [
                'institution_id' => Lesson::withTrashed()->whereKey($lessonId)->value('institution_id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
        }

        /** @var LessonProgress $parent */
        $parent = LessonProgress::query()->where($cle)->lockForUpdate()->firstOrFail();

        return $parent;
    }

    /** Somme des chapitres de la leçon, en lecture verrouillante, puis écriture. */
    private function writeAggregate(LessonProgress $parent, int $userId, int $lessonId): int
    {
        $seconds = (int) ChapterProgress::query()
            ->where('user_id', $userId)
            ->whereIn('chapter_id', $this->chapterIdsOf($lessonId))
            ->lockForUpdate()
            ->sum('time_spent_seconds');

        // Division entière : on n'affiche jamais plus que le temps mesuré.
        $minutes = intdiv($seconds, 60);

        if ($parent->time_spent_minutes !== $minutes) {
            $parent->time_spent_minutes = $minutes;
            $parent->save();
        }

        return $minutes;
    }

    /**
     * Chapitres actifs de la leçon : le même périmètre que la remise à zéro et
     * que le pourcentage (LessonChapterProgressCalculator), qui ignorent tous
     * deux les chapitres supprimés. Un périmètre plus large ferait diverger
     * l'agrégat de ce que l'écran compte par ailleurs.
     *
     * @return list<int>
     */
    private function chapterIdsOf(int $lessonId): array
    {
        /** @var list<int> $ids */
        $ids = Chapter::query()->where('lesson_id', $lessonId)->pluck('id')->all();

        return $ids;
    }
}
