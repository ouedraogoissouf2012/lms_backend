<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Classe;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Notifications liées aux visioconférences (programmation, démarrage).
 *
 * Extrait de `NotificationService` (split notification-service, §1.1 ≤300 l).
 *
 * ## DI strict (§1.6 D)
 *
 * - `NotificationDispatcher` injecté pour l'envoi bas niveau.
 * - `LoggerInterface` PSR-3 injecté (remplace Facade `Log` de l'origine).
 *
 * @see app/Services/NotificationService.php  Facade orchestrateur
 */
final class VisioNotificationDispatcher
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly VisioNotificationIdempotencyGuard $idempotency,
    ) {}

    /**
     * Notifier les étudiants de la classe qu'une visio a été programmée.
     *
     * Anti-double-envoi : skip si des notifications de ce type ont déjà été
     * envoyées pour cette séance dans les dernières 24 h.
     *
     * @param  array<string, mixed>  $seanceData
     */
    public function notifyVisioScheduled(int $seanceId, array $seanceData): int
    {
        return $this->idempotency->run(
            Notification::TYPE_VISIO_SCHEDULED,
            $seanceId,
            fn (): int => $this->dispatchScheduled($seanceId, $seanceData),
        );
    }

    /** @param array<string, mixed> $seanceData */
    private function dispatchScheduled(int $seanceId, array $seanceData): int
    {
        $students = $this->resolveStudentsForScheduled($seanceId, $seanceData);

        if ($students->isEmpty()) {
            $this->logger->warning('Aucun étudiant trouvé pour notification', [
                'seance_id' => $seanceId,
                'klassci_classe_id' => $seanceData['klassci_classe_id'] ?? null,
            ]);

            return 0;
        }

        $matiere = $seanceData['matiere_nom'] ?? 'Matière inconnue';
        $enseignant = $seanceData['enseignant_nom'] ?? 'Enseignant';

        $title = 'Visioconférence programmée';
        $message = "Une visioconférence a été programmée en {$matiere} avec {$enseignant}.";
        $data = [
            'seance_id' => $seanceId,
            'matiere' => $matiere,
            'enseignant' => $enseignant,
        ];

        return $this->dispatcher->sendToMany($students, Notification::TYPE_VISIO_SCHEDULED, $title, $message, $data);
    }

    /**
     * Notifier le démarrage imminent d'une visio (étudiants + enseignant).
     *
     * @param  array<string, mixed>  $seanceData
     */
    public function notifyVisioStarting(int $seanceId, array $seanceData): int
    {
        return $this->idempotency->run(
            Notification::TYPE_VISIO_STARTING,
            $seanceId,
            fn (): int => $this->dispatchStarting($seanceId, $seanceData),
        );
    }

    /** @param array<string, mixed> $seanceData */
    private function dispatchStarting(int $seanceId, array $seanceData): int
    {
        $students = $this->resolveStudentsForStarting($seanceData);
        $teacher = $this->resolveTeacher($seanceData);

        $count = 0;
        $matiere = $seanceData['matiere_nom'] ?? 'Matière inconnue';

        if ($students->isNotEmpty()) {
            $title = 'Visioconférence en cours';
            $message = "La visioconférence de {$matiere} a démarré. Rejoignez maintenant !";
            $data = [
                'seance_id' => $seanceId,
                'matiere' => $matiere,
            ];

            $count += $this->dispatcher->sendToMany($students, Notification::TYPE_VISIO_STARTING, $title, $message, $data);
        }

        if ($teacher) {
            $title = 'Votre visioconférence a démarré';
            $message = "Votre visioconférence de {$matiere} est maintenant active.";
            $data = [
                'seance_id' => $seanceId,
                'matiere' => $matiere,
            ];

            $this->dispatcher->send($teacher, Notification::TYPE_VISIO_STARTING, $title, $message, $data);
            $count++;
        }

        return $count;
    }

    /**
     * Résout les étudiants à notifier pour la programmation (avec logs détaillés).
     *
     * @param  array<string, mixed>  $seanceData
     * @return Collection<int, User>
     */
    private function resolveStudentsForScheduled(int $seanceId, array $seanceData): Collection
    {
        $students = collect();

        if (! isset($seanceData['klassci_classe_id'])) {
            return $students;
        }

        $classe = Classe::where('klassci_id', $seanceData['klassci_classe_id'])->first();

        if (! $classe) {
            $this->logger->warning('Classe non trouvée pour notification', [
                'klassci_classe_id' => $seanceData['klassci_classe_id'],
            ]);

            return $students;
        }

        $students = User::query()
            ->whereHas('classes', function ($query) use ($classe) {
                $query->where('classes.id', $classe->id)
                    ->where('classe_etudiant.statut', 'actif');
            })
            ->where('role', 'etudiant')
            ->get();

        $this->logger->info('Étudiants trouvés pour notification visio', [
            'classe_id' => $classe->id,
            'students_count' => $students->count(),
        ]);

        return $students;
    }

    /**
     * Résout les étudiants pour le démarrage (silencieux, pas de logs détaillés).
     *
     * @param  array<string, mixed>  $seanceData
     * @return Collection<int, User>
     */
    private function resolveStudentsForStarting(array $seanceData): Collection
    {
        if (! isset($seanceData['klassci_classe_id'])) {
            return collect();
        }

        $classe = Classe::where('klassci_id', $seanceData['klassci_classe_id'])->first();

        if (! $classe) {
            return collect();
        }

        return User::query()
            ->whereHas('classes', function ($query) use ($classe) {
                $query->where('classes.id', $classe->id)
                    ->where('classe_etudiant.statut', 'actif');
            })
            ->where('role', 'etudiant')
            ->get();
    }

    /**
     * Résout l'enseignant à notifier (si présent dans `klassci_enseignant_id`).
     *
     * @param  array<string, mixed>  $seanceData
     */
    private function resolveTeacher(array $seanceData): ?User
    {
        if (! isset($seanceData['klassci_enseignant_id'])) {
            return null;
        }

        return User::where('klassci_id', $seanceData['klassci_enseignant_id'])
            ->where('role', 'enseignant')
            ->first();
    }
}
