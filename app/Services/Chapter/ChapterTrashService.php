<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Services\Visio\AttendanceLifecycleService;
use Psr\Log\LoggerInterface;
use Tests\Feature\Chapter\ChapterTrashAndRestoreTest;
use Throwable;

/**
 * La corbeille d'un chapitre : y mettre, en sortir (#689).
 *
 * ## Le défaut corrigé
 *
 * `ChapterCrudService::delete()` détruisait les fichiers AVANT de mettre la
 * ligne à la corbeille — `purgeChapter()` faisant un `deleteDirectory()` sur les
 * deux disques. La corbeille ne contenait donc que des coquilles vides, et
 * aucune restauration n'existait. Le mécanisme avait le coût du soft delete
 * sans aucun de ses bénéfices.
 *
 * Supprimer un chapitre détruit pourtant un cours entier, document source de
 * l'enseignant compris. La décision produit a été de rendre la corbeille réelle.
 *
 * ## Où les fichiers sont détruits désormais
 *
 * À la **purge**, à échéance de rétention — c'est #674. Tant qu'un chapitre est
 * à la corbeille, ses fichiers l'attendent.
 *
 * ## Pourquoi un service distinct de `ChapterCrudService`
 *
 * Le cycle de vie de la corbeille est une responsabilité propre, et le service
 * CRUD était déjà à 299 lignes pour une limite de 300 : y ajouter la
 * restauration l'aurait fait déborder. Même découpage que
 * {@see AttendanceLifecycleService}, qui porte l'arrivée et
 * le départ d'un participant.
 *
 * @see ChapterTrashAndRestoreTest
 */
final class ChapterTrashService
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Met un chapitre à la corbeille — SANS toucher à ses fichiers.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function trash(int $id): array
    {
        try {
            $chapter = Chapter::findOrFail($id);
            $chapter->delete();

            $this->logger->info('Chapitre mis à la corbeille', ['chapter_id' => $id]);

            return $this->ok('Chapitre supprimé');
        } catch (Throwable $e) {
            return $this->failed('Erreur suppression chapitre', $id, $e);
        }
    }

    /**
     * Sort un chapitre de la corbeille.
     *
     * Idempotent : restaurer un chapitre qui n'a jamais été supprimé réussit
     * sans rien changer. Répondre en erreur obligerait l'appelant à connaître
     * l'état courant avant d'agir, pour un geste sans conséquence.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function restore(int $id): array
    {
        try {
            // `withTrashed()` est indispensable : sans lui, la cible d'une
            // restauration est par définition introuvable.
            $chapter = Chapter::withTrashed()->findOrFail($id);
            $chapter->restore();

            $this->logger->info('Chapitre restauré', ['chapter_id' => $id]);

            return $this->ok('Chapitre restauré');
        } catch (Throwable $e) {
            return $this->failed('Erreur restauration chapitre', $id, $e);
        }
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function ok(string $message): array
    {
        return [
            'status' => 200,
            'payload' => ['success' => true, 'message' => $message],
        ];
    }

    /**
     * Le détail de l'exception reste dans le journal, jamais dans la réponse.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function failed(string $context, int $id, Throwable $e): array
    {
        $this->logger->error($context, ['chapter_id' => $id, 'error' => $e->getMessage()]);

        return [
            'status' => 500,
            'payload' => ['success' => false, 'message' => 'Une erreur est survenue.'],
        ];
    }
}
