<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Http\Requests\ReorderChaptersRequest;
use App\Http\Requests\StoreChapterRequest;
use App\Http\Requests\UpdateChapterRequest;
use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\User;
use App\Services\FileConversionService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CRUD chapitres. Lectures étudiant filtrées par classe (#621 / #482).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 */
final class ChapterCrudService
{
    public function __construct(
        private readonly FileConversionService $fileConversionService,
        private readonly LoggerInterface $logger,
        private readonly ChapterReadGate $reads,
    ) {}

    /**
     * Liste des chapitres d'une leçon (ordered scope).
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function listByLesson(int $lessonId, User $user): array
    {
        try {
            $lesson = Lesson::findOrFail($lessonId);
            if (! $this->reads->canReadLesson($lesson, $user)) {
                return $this->notFound('Chapitres introuvables');
            }
            $chapters = $lesson->chapters()->ordered()->get();

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'data' => $chapters,
                    'message' => 'Chapitres récupérés',
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur récupération chapitres', [
                'lesson_id' => $lessonId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Détails d'un chapitre avec sa leçon parente.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function show(int $id, User $user): array
    {
        try {
            $chapter = Chapter::with('lesson')->findOrFail($id);
            if (! $this->reads->canRead($chapter, $user)) {
                return $this->notFound('Chapitre non trouvé');
            }

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'data' => $chapter,
                ],
            ];
        } catch (Throwable $e) {
            return $this->notFound('Chapitre non trouvé');
        }
    }

    /** @return array{status:int, payload: array<string, mixed>} */
    private function notFound(string $message): array
    {
        return [
            'status' => 404,
            'payload' => [
                'success' => false,
                'message' => $message,
            ],
        ];
    }

    /**
     * Crée un nouveau chapitre pour une leçon.
     *
     * Mappe les champs français du request vers les colonnes anglaises
     * du modèle. Si `ordre` n'est pas fourni, place le chapitre en fin
     * de liste (max(order) + 1).
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function create(StoreChapterRequest $request, int $lessonId, ?int $authUserId): array
    {
        try {
            $lesson = Lesson::findOrFail($lessonId);

            $validated = $request->validated();

            $data = [
                'title' => $validated['titre'] ?? null,
                'description' => $validated['description'] ?? null,
                'lesson_id' => $lessonId,
                'matiere_id' => $lesson->matiere_id,
                'enseignant_id' => $authUserId,
                // Scope tenant explicite hérité de la leçon parente (défense
                // en profondeur, fix E2E #211 flow 2).
                'institution_id' => $lesson->institution_id,
            ];

            if (isset($validated['type_contenu'])) {
                $data['content_type'] = $validated['type_contenu'];
            }

            // Corps du chapitre (clés EN = colonnes du modèle) : texte/markdown,
            // lien vidéo, lien externe, autoplay. Sans ça, un chapitre créé via
            // l'API restait vide (seul l'upload de fichier était persisté).
            foreach (['content', 'video_url', 'external_link', 'autoplay_video'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $data[$field] = $validated[$field];
                }
            }

            if (isset($validated['ordre']) && $validated['ordre'] !== null) {
                $data['order'] = (int) $validated['ordre'];
            } else {
                $maxOrder = $lesson->chapters()->max('order') ?? 0;
                $data['order'] = $maxOrder + 1;
            }

            $chapter = Chapter::create($data);

            $this->logger->info('Chapitre créé', [
                'chapter_id' => $chapter->id,
                'lesson_id' => $lessonId,
            ]);

            return [
                'status' => 201,
                'payload' => [
                    'success' => true,
                    'data' => $chapter->load('lesson'),
                    'message' => 'Chapitre créé avec succès',
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur création chapitre', [
                'lesson_id' => $lessonId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Met à jour un chapitre.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function update(UpdateChapterRequest $request, int $id): array
    {
        try {
            $chapter = Chapter::findOrFail($id);
            $chapter->update($request->validated());

            $this->logger->info('Chapitre mis à jour', ['chapter_id' => $id]);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'data' => $chapter->fresh(),
                    'message' => 'Chapitre mis à jour',
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur mise à jour chapitre', [
                'chapter_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Supprime un chapitre (purge fichiers + delete row).
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function delete(int $id): array
    {
        try {
            $chapter = Chapter::findOrFail($id);
            $this->fileConversionService->deleteChapterFiles($id);
            $chapter->delete();

            $this->logger->info('Chapitre supprimé', ['chapter_id' => $id]);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Chapitre supprimé',
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur suppression chapitre', [
                'chapter_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Réorganise l'ordre des chapitres d'une leçon (drag & drop).
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function reorder(ReorderChaptersRequest $request, int $lessonId): array
    {
        try {
            $chapters = $request->validated()['chapters'];
            foreach ($chapters as $chapterData) {
                Chapter::where('id', $chapterData['id'])
                    ->where('lesson_id', $lessonId)
                    ->update(['order' => $chapterData['order']]);
            }

            $this->logger->info('Chapitres réorganisés', [
                'lesson_id' => $lessonId,
                'count' => count($chapters),
            ]);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Ordre des chapitres mis à jour',
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur réorganisation chapitres', [
                'lesson_id' => $lessonId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Une erreur est survenue.',
                ],
            ];
        }
    }
}
