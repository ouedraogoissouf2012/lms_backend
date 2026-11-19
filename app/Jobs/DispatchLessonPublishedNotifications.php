<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\User;
use App\Services\Audience\ElevesDeLaClasse;
use App\Services\TenantManager;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * #538 — Fan-out ASYNCHRONE des notifications de publication d'une leçon.
 *
 * La requête `POST /lessons/{id}/publish` ne fait que dispatcher ce job. Le job :
 *  - repose le tenant de la leçon (le worker n'exécute PAS ResolveInstitution :
 *    sans `set()`, le global scope BelongsToInstitution devient no-op → fuite ou
 *    écriture cross-tenant — même cause-racine que #536) ;
 *  - avertit les élèves inscrits dans la classe de la leçon ;
 *  - insère les notifications en lot.
 *
 * ## Les destinataires (D614)
 *
 * Les élèves de la classe LOCALE de la leçon, par la règle d'inscription de l'école
 * ({@see ElevesDeLaClasse}, partagée avec l'audience des séances) : un élève qui peut
 * lire la leçon est averti. Aucun appel KLASSCI. Avant, le job interrogeait KLASSCI
 * `/matieres/{id}` avec l'id LOCAL de la matière, puis lisait `classe_ids` et
 * `etudiant_ids`, deux clés absentes de la forme réelle : personne n'était averti, et
 * jamais en école autonome. Une leçon ancienne sans classe n'avertit personne.
 *
 * La signature est GARDÉE (`matiereId` compris, désormais nullable) : un job déjà en
 * file au déploiement se désérialise. `matiereId` ne sert plus qu'aux données de la
 * notification.
 *
 * Best-effort (comme le comportement pré-#538) : toute erreur est logguée sans être
 * relancée, et `$tries = 1` évite les doublons qu'un retry après insertion partielle
 * produirait. DETTE tracée : sur échec transitoire, certains étudiants peuvent ne pas
 * être notifiés (choix assumé vs risque de doublons).
 *
 * @see app/Services/Lesson/LessonCrudOperationsService.php (dispatcher)
 * @see app/Jobs/GenerateReportPdf.php (#536 — même pattern tenant en job)
 */
final class DispatchLessonPublishedNotifications implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Best-effort : pas de retry (éviter les doublons après insertion partielle). */
    public int $tries = 1;

    /** #539 — rester dans le budget de drain (`queue:work --max-time=55`). */
    public int $timeout = 50;

    public function __construct(
        public readonly int $lessonId,
        public readonly ?int $matiereId,
        public readonly string $lessonTitle,
        public readonly int $institutionId,
    ) {}

    public function handle(
        ElevesDeLaClasse $eleves,
        TenantManager $tenantManager,
        LoggerInterface $logger,
        CaughtExceptionLog $journal,
    ): void {
        // Purge un tenant résiduel d'un job précédent (worker persistant), puis
        // repose le tenant de la leçon.
        $tenantManager->reset();

        try {
            $institution = Institution::find($this->institutionId);
            if ($institution === null) {
                $logger->warning('Fan-out publication : institution introuvable', [
                    'lesson_id' => $this->lessonId,
                    'institution_id' => $this->institutionId,
                ]);

                return;
            }
            $tenantManager->set($institution);

            $classe = $this->classeDeLaLecon();
            if ($classe === null) {
                $logger->info('Fan-out publication : leçon sans classe, personne à avertir', [
                    'lesson_id' => $this->lessonId,
                ]);

                return;
            }

            $userIds = $eleves->de($classe, $this->institutionId)->map(static fn (User $eleve): int => $eleve->id)->all();
            if ($userIds === []) {
                return;
            }

            $this->insertNotifications($userIds);
        } catch (Throwable $e) {
            // Best-effort : la publication reste acquise (comportement pré-#538).
            $journal->warning(
                'Erreur lors du fan-out des notifications de publication',
                $e,
                ['lesson_id' => $this->lessonId],
            );
        } finally {
            // Ne pas fuiter le tenant vers le prochain job du worker.
            $tenantManager->reset();
        }
    }

    /** La classe LOCALE de la leçon, bornée explicitement à son établissement. */
    private function classeDeLaLecon(): ?Classe
    {
        $lecon = Lesson::query()->where('institution_id', $this->institutionId)->find($this->lessonId);
        if (! $lecon instanceof Lesson || ! is_numeric($lecon->classe_id)) {
            return null;
        }

        $classe = Classe::query()->where('institution_id', $this->institutionId)->find((int) $lecon->classe_id);

        return $classe instanceof Classe ? $classe : null;
    }

    /**
     * Insertion groupée des notifications (au lieu de N `create()`).
     *
     * @param  array<int, int>  $userIds
     */
    private function insertNotifications(array $userIds): void
    {
        $now = now();
        $data = json_encode(
            ['lesson_id' => $this->lessonId, 'matiere_id' => $this->matiereId],
            JSON_THROW_ON_ERROR,
        );
        $message = 'Un nouveau cours "'.$this->lessonTitle.'" est maintenant disponible';

        $rows = [];
        foreach ($userIds as $userId) {
            $rows[] = [
                'user_id' => $userId,
                'institution_id' => $this->institutionId,
                'type' => Notification::TYPE_LESSON_PUBLISHED,
                'title' => 'Nouveau cours disponible',
                'message' => $message,
                'data' => $data,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Notification::insert($rows);
    }

    public function failed(Throwable $exception): void
    {
        app(CaughtExceptionLog::class)->error('Job DispatchLessonPublishedNotifications failed after all retries', $exception);
    }
}
