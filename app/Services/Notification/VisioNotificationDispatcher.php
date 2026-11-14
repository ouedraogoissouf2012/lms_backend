<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
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
 * - `VisioNotificationTargets` injecté : qui avertir (élèves, enseignant,
 *   établissement de la séance).
 *
 * @see app/Services/NotificationService.php  Facade orchestrateur
 */
final class VisioNotificationDispatcher
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly VisioNotificationIdempotencyGuard $idempotency,
        private readonly VisioNotificationTargets $cibles,
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
            VisioNotificationTargets::idLocal($seanceData),
        );
    }

    /** @param array<string, mixed> $seanceData */
    private function dispatchScheduled(int $seanceId, array $seanceData): int
    {
        $students = $this->cibles->elevesProgrammation($seanceId, $seanceData);

        if ($students->isEmpty()) {
            $this->logger->warning('Aucun étudiant trouvé pour notification', [
                'seance_id' => $seanceId,
                'klassci_classe_id' => $seanceData['klassci_classe_id'] ?? null,
            ]);

            return 0;
        }

        $matiere = is_string($seanceData['matiere_nom'] ?? null) ? $seanceData['matiere_nom'] : 'Matière inconnue';
        $enseignant = is_string($seanceData['enseignant_nom'] ?? null) ? $seanceData['enseignant_nom'] : 'Enseignant';

        $title = 'Visioconférence programmée';
        $message = "Une visioconférence a été programmée en {$matiere} avec {$enseignant}.";
        $data = [
            'seance_id' => $seanceId,
            'matiere' => $matiere,
            'enseignant' => $enseignant,
        ] + self::marqueLocale($seanceData);

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
            VisioNotificationTargets::idLocal($seanceData),
        );
    }

    /** @param array<string, mixed> $seanceData */
    private function dispatchStarting(int $seanceId, array $seanceData): int
    {
        $students = $this->cibles->elevesDemarrage($seanceData);
        $teacher = $this->cibles->enseignant($seanceData);

        $count = 0;
        $matiere = is_string($seanceData['matiere_nom'] ?? null) ? $seanceData['matiere_nom'] : 'Matière inconnue';

        if ($students->isNotEmpty()) {
            $title = 'Visioconférence en cours';
            $message = "La visioconférence de {$matiere} a démarré. Rejoignez maintenant !";
            $data = ['seance_id' => $seanceId, 'matiere' => $matiere] + self::marqueLocale($seanceData);

            $count += $this->dispatcher->sendToMany($students, Notification::TYPE_VISIO_STARTING, $title, $message, $data);
        }

        if ($teacher) {
            $title = 'Votre visioconférence a démarré';
            $message = "Votre visioconférence de {$matiere} est maintenant active.";
            $data = ['seance_id' => $seanceId, 'matiere' => $matiere] + self::marqueLocale($seanceData);

            $this->dispatcher->send($teacher, Notification::TYPE_VISIO_STARTING, $title, $message, $data);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $seanceData
     * @return array{seance_locale_id?: int}
     */
    private static function marqueLocale(array $seanceData): array
    {
        $id = VisioNotificationTargets::idLocal($seanceData);

        return $id === null ? [] : ['seance_locale_id' => $id];
    }
}
