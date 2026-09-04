<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

final class SeanceRecordingAttachmentResolver
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SeanceRecordingAttachmentGuard $guard,
    ) {}

    public function attachReadyRecording(
        Seance $seance,
        string $recordingUrl,
        ?string $title = null,
        string $provider = 'external',
    ): RecordingAttachmentResult {
        if ($seance->klassci_matiere_id === null) {
            return $this->fail($seance, 'missing_klassci_matiere_id', ['recording_url' => $recordingUrl]);
        }

        $matiere = $this->resolveMatiere($seance);
        if ($matiere === null) {
            return $this->fail($seance, 'matiere_not_found', [
                'klassci_matiere_id' => $seance->klassci_matiere_id,
                'recording_url' => $recordingUrl,
            ]);
        }

        $lessons = $this->candidateLessons($seance, $matiere);
        if ($lessons->isEmpty()) {
            return $this->fail($seance, 'lesson_not_found', [
                'matiere_id' => $matiere->id,
                'recording_url' => $recordingUrl,
            ]);
        }

        if ($lessons->count() > 1) {
            return $this->fail($seance, 'ambiguous_lesson', [
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

    private function resolveMatiere(Seance $seance): ?Matiere
    {
        return Matiere::query()
            ->where('institution_id', $seance->institution_id)
            ->where('klassci_id', $seance->klassci_matiere_id)
            ->first();
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function candidateLessons(Seance $seance, Matiere $matiere): Collection
    {
        $query = Lesson::query()
            ->where('institution_id', $seance->institution_id)
            ->where('matiere_id', $matiere->id);

        $classe = $this->resolveClasse($seance);
        if ($classe !== null) {
            $query->where('classe_id', $classe->id);
        }

        $teacherIds = $this->teacherCandidateIds($seance);

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

    private function resolveClasse(Seance $seance): ?Classe
    {
        if ($seance->klassci_classe_id === null) {
            return null;
        }

        return Classe::query()
            ->where('institution_id', $seance->institution_id)
            ->where('klassci_id', $seance->klassci_classe_id)
            ->first();
    }

    /**
     * Les `users.id` LOCAUX de l'enseignant de la séance.
     *
     * ## Le défaut corrigé (#707)
     *
     * Cette méthode renvoyait `[$klassciTeacherId]` — l'identifiant **KLASSCI
     * brut** — puis y ajoutait les `users.id` **locaux**, et le tout était
     * confronté à `lessons.enseignant_id`. **Deux espaces d'identifiants dans la
     * même comparaison.**
     *
     * Or `lessons.enseignant_id` est un `users.id` local : six FormRequests le
     * comparent à `$user->id`, et `Lesson` déclare
     * `belongsTo(User::class, 'enseignant_id')`. Seul le commentaire de la
     * migration disait le contraire — c'était la source de la confusion.
     *
     * Conséquence : si un collègue portait un `users.id` égal au
     * `klassci_enseignant_id` de la séance, et enseignait la même matière à la
     * même classe, l'enregistrement était publié **dans son cours**, visible par
     * ses étudiants.
     *
     * ## Les trois états, distingués
     *
     * - `null` — aucun enseignant déclaré sur la séance : pas de contrainte.
     * - `[]` — enseignant déclaré mais **introuvable** localement. L'appelant
     *   doit conclure `lesson_not_found`, jamais lever la contrainte.
     * - liste non vide — les `users.id` locaux correspondants.
     *
     * @return list<int>|null
     */
    private function teacherCandidateIds(Seance $seance): ?array
    {
        if ($seance->klassci_enseignant_id === null) {
            return null;
        }

        $localIds = User::query()
            ->where('institution_id', $seance->institution_id)
            ->where('klassci_enseignant_id', (int) $seance->klassci_enseignant_id)
            ->pluck('id');

        $ids = [];
        foreach ($localIds as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
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

        $chapter->fill([
            'lesson_id' => $lesson->id,
            'matiere_id' => $lesson->matiere_id,
            'enseignant_id' => $lesson->enseignant_id,
            'institution_id' => $lesson->institution_id,
            'title' => $title ?? $this->defaultTitle($seance),
            'description' => 'Visio recording attached automatically.',
            'content_type' => 'video',
            'content' => null,
            'video_url' => $recordingUrl,
            'video_provider' => $provider,
            'allow_download' => false,
            'autoplay_video' => false,
            'notes_enseignant' => $this->recordingNotes($seance),
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

    private function defaultTitle(Seance $seance): string
    {
        $label = $seance->titre ?: 'Seance '.$seance->klassci_seance_id;

        return 'Recording - '.$label;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fail(Seance $seance, string $reason, array $context): RecordingAttachmentResult
    {
        $this->logger->warning('Visio recording attachment failed', [
            'reason' => $reason,
            'seance_id' => $seance->id,
            'klassci_seance_id' => $seance->klassci_seance_id,
        ] + $context);

        return RecordingAttachmentResult::failed($reason, $context);
    }
}
