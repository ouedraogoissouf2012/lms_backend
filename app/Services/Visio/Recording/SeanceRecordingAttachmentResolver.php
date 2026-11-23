<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\RecordingFailureReason;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Seance;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

final class SeanceRecordingAttachmentResolver
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SeanceRecordingAttachmentGuard $guard,
        private readonly TitreDEnregistrement $titres,
        private readonly RosterAuthorityFactory $autorites,
    ) {}

    public function attachReadyRecording(
        Seance $seance,
        string $recordingUrl,
        ?string $title = null,
        string $provider = 'external',
    ): RecordingAttachmentResult {
        $cibles = $this->ciblesDe($seance);

        $matiere = $cibles->matiere($seance);
        if ($matiere instanceof RecordingFailureReason) {
            return $this->fail($seance, $matiere, [
                'klassci_matiere_id' => $seance->klassci_matiere_id,
                'matiere_id' => $seance->matiere_id,
                'recording_url' => $recordingUrl,
            ]);
        }

        $lessons = $this->candidateLessons($seance, $matiere, $cibles);
        if ($lessons->isEmpty()) {
            return $this->fail($seance, RecordingFailureReason::LessonNotFound, [
                'matiere_id' => $matiere->id,
                'recording_url' => $recordingUrl,
            ]);
        }

        if ($lessons->count() > 1) {
            return $this->fail($seance, RecordingFailureReason::AmbiguousLesson, [
                'lesson_ids' => $lessons->pluck('id')->values()->all(),
                'recording_url' => $recordingUrl,
            ]);
        }

        $lesson = $lessons->first();
        $chapter = $this->guard->run(
            $seance->id,
            fn (): Chapter => $this->upsertVideoChapter($seance, $lesson, $recordingUrl, $title, $provider),
        );

        return RecordingAttachmentResult::attached($lesson, $chapter);
    }

    /**
     * Les cibles se choisissent par le MODE de l'école, au seul point de résolution (#1483).
     * L'école est lue sur la séance : un job n'a pas de tenant. Une école supprimée en douceur
     * garde son mode.
     */
    private function ciblesDe(Seance $seance): CiblesDeLaSeance
    {
        $ecole = $seance->institution_id === null
            ? null
            : Institution::withTrashed()->find($seance->institution_id);

        return $seance->klassci_matiere_id === null ? new LocaleCiblesDeLaSeance : new KlassciCiblesDeLaSeance;
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function candidateLessons(Seance $seance, Matiere $matiere, CiblesDeLaSeance $cibles): Collection
    {
        $query = Lesson::query()
            ->where('institution_id', $seance->institution_id)
            ->where('matiere_id', $matiere->id);

        $classe = $cibles->classe($seance);
        if ($classe !== null) {
            $query->where('classe_id', $classe->id);
        }

        $teacherIds = $cibles->enseignants($seance);

        if ($teacherIds !== null) {
            // Enseignant DÉCLARÉ mais introuvable localement : aucun candidat.
            // Ne surtout pas retomber sur une recherche sans filtre — la vidéo
            // atterrirait dans le cours de n'importe quel enseignant de la même
            // matière et de la même classe (#707).
            if ($teacherIds === []) {
                return new Collection;
            }

            $query->whereIn('enseignant_id', $teacherIds);
        }

        return $query->orderBy('id')->get();
    }

    private function upsertVideoChapter(
        Seance $seance,
        Lesson $lesson,
        string $recordingUrl,
        ?string $title,
        string $provider,
    ): Chapter {
        $chapter = $this->existingRecordingChapter($seance, $lesson) ?? new Chapter;
        $isNew = ! $chapter->exists;
        // Lu AVANT le `fill`, qui remplace les notes où le titre automatique est gardé (#854).
        $automatique = $this->titres->automatique($seance, $title);
        $titre = $this->titres->retenu($chapter, $seance, $automatique);

        $chapter->fill([
            'lesson_id' => $lesson->id,
            'matiere_id' => $lesson->matiere_id,
            'enseignant_id' => $lesson->enseignant_id,
            'institution_id' => $lesson->institution_id,
            'title' => $titre,
            'description' => 'Visio recording attached automatically.',
            'content_type' => 'video',
            'content' => null,
            'video_url' => $recordingUrl,
            'video_provider' => $provider,
            'allow_download' => false,
            'autoplay_video' => false,
            'notes_enseignant' => $this->recordingNotes($seance) + [TitreDEnregistrement::NOTE => $automatique],
        ]);

        if ($isNew) {
            $chapter->order = $this->nextChapterOrder($lesson);
        }

        $chapter->save();

        return $chapter;
    }

    private function existingRecordingChapter(Seance $seance, Lesson $lesson): ?Chapter
    {
        return Chapter::query()
            ->where('lesson_id', $lesson->id)
            ->where('content_type', 'video')
            ->get()
            ->first(fn (Chapter $chapter): bool => $this->isRecordingChapterForSeance($chapter, $seance));
    }

    private function isRecordingChapterForSeance(Chapter $chapter, Seance $seance): bool
    {
        $notes = $chapter->notes_enseignant;

        return is_array($notes)
            && ($notes['source'] ?? null) === 'visio_recording'
            && (int) ($notes['seance_id'] ?? 0) === (int) $seance->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordingNotes(Seance $seance): array
    {
        return [
            'source' => 'visio_recording',
            'seance_id' => $seance->id,
            'klassci_seance_id' => $seance->klassci_seance_id,
            'klassci_matiere_id' => $seance->klassci_matiere_id,
            'attached_at' => now()->toIso8601String(),
        ];
    }

    private function nextChapterOrder(Lesson $lesson): int
    {
        $maxOrder = Chapter::query()->where('lesson_id', $lesson->id)->max('order');

        return (is_numeric($maxOrder) ? (int) $maxOrder : 0) + 1;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fail(Seance $seance, RecordingFailureReason $reason, array $context): RecordingAttachmentResult
    {
        $this->logger->warning('Visio recording attachment failed', [
            'reason' => $reason->value,
            'seance_id' => $seance->id,
            'klassci_seance_id' => $seance->klassci_seance_id,
        ] + $context);

        return RecordingAttachmentResult::failed($reason, $context);
    }
}
