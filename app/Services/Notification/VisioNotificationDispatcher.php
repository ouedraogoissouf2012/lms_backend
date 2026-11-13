<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Classe;
use App\Models\Notification;
use App\Models\Seance;
use App\Models\User;
use App\Services\Audience\ClasseAudienceSource;
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
 * - `ClasseAudienceSource` injecté : les élèves d'une séance sans classe KLASSCI
 *   sont ceux de l'admission ({@see elevesDeLaSeanceLocale()}).
 *
 * @see app/Services/NotificationService.php  Facade orchestrateur
 */
final class VisioNotificationDispatcher
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly VisioNotificationIdempotencyGuard $idempotency,
        private readonly ClasseAudienceSource $audience,
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
            self::idLocal($seanceData),
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
            self::idLocal($seanceData),
        );
    }

    /** @param array<string, mixed> $seanceData */
    private function dispatchStarting(int $seanceId, array $seanceData): int
    {
        $students = $this->resolveStudentsForStarting($seanceData);
        $teacher = $this->resolveTeacher($seanceData);

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
     * Résout les étudiants à notifier pour la programmation (avec logs détaillés).
     *
     * @param  array<string, mixed>  $seanceData
     * @return Collection<int, User>
     */
    private function resolveStudentsForScheduled(int $seanceId, array $seanceData): Collection
    {
        $students = collect();

        if (! isset($seanceData['klassci_classe_id'])) {
            return $this->elevesDeLaSeanceLocale($seanceData);
        }

        $classe = $this->resolveClasse($seanceData);

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
            return $this->elevesDeLaSeanceLocale($seanceData);
        }

        $classe = $this->resolveClasse($seanceData);

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
     * Une séance sans classe KLASSCI : ses élèves sont ceux de l'audience de
     * l'admission, lue sur la séance que l'appelant désigne par son id LOCAL
     * (`seance_locale_id`). Sans cet id, rien n'est deviné : l'identifiant du job
     * peut être un identifiant KLASSCI, qui désignerait une autre séance.
     *
     * @param  array<string, mixed>  $seanceData
     * @return Collection<int, User>
     */
    private function elevesDeLaSeanceLocale(array $seanceData): Collection
    {
        $id = self::idLocal($seanceData);

        // Hors contexte HTTP le scope est inerte ; l'audience borne la classe à l'établissement de la séance.
        $seance = $id === null ? null : Seance::query()->withoutGlobalScope('institution')->find($id);

        return $seance === null ? collect() : $this->audience->studentsOf($seance);
    }

    /**
     * L'id LOCAL de la séance (`seances.id`), que chaque source déclare : la clé de
     * la garde d'idempotence, et la marque des notifications. Null pour un job mis
     * en file avant que la source ne le déclare.
     *
     * @param  array<string, mixed>  $seanceData
     */
    private static function idLocal(array $seanceData): ?int
    {
        $id = $seanceData['seance_locale_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $seanceData
     * @return array{seance_locale_id?: int}
     */
    private static function marqueLocale(array $seanceData): array
    {
        $id = self::idLocal($seanceData);

        return $id === null ? [] : ['seance_locale_id' => $id];
    }

    /**
     * L'enseignant KLASSCI à avertir (`klassci_enseignant_id`), cherché dans
     * l'établissement de la séance.
     *
     * @param  array<string, mixed>  $seanceData
     */
    private function resolveTeacher(array $seanceData): ?User
    {
        if (! isset($seanceData['klassci_enseignant_id'])) {
            return null;
        }

        $etablissement = $this->etablissementDe($seanceData);

        return $etablissement === null ? null : User::query()->withoutGlobalScope('institution')
            ->where('institution_id', $etablissement)
            ->where('klassci_id', $seanceData['klassci_enseignant_id'])
            ->where('role', 'enseignant')
            ->first();
    }

    /**
     * La classe KLASSCI de la séance, cherchée dans l'établissement de la séance
     * (#473) : `klassci_id` n'est unique que par établissement.
     *
     * @param  array<string, mixed>  $seanceData
     */
    private function resolveClasse(array $seanceData): ?Classe
    {
        $etablissement = $this->etablissementDe($seanceData);

        return $etablissement === null ? null : Classe::query()->withoutGlobalScope('institution')
            ->where('institution_id', $etablissement)
            ->where('klassci_id', $seanceData['klassci_classe_id'])
            ->first();
    }

    /**
     * L'établissement de la séance, lu sur la SÉANCE désignée par son id local, jamais
     * sur la charge : le job tourne sans tenant (le worker oublie celui de la requête).
     * Sans id local (job mis en file avant), repli sur `institution_id` de la charge ;
     * sans l'un ni l'autre, null : rien n'est envoyé plutôt que deviné.
     *
     * @param  array<string, mixed>  $seanceData
     */
    private function etablissementDe(array $seanceData): ?int
    {
        $id = self::idLocal($seanceData);
        $etablissement = $id === null
            ? $seanceData['institution_id'] ?? null
            : Seance::query()->withoutGlobalScope('institution')->whereKey($id)->value('institution_id');

        if (! is_numeric($etablissement)) {
            $this->logger->warning('Avertissement visio sans établissement : aucun envoi');

            return null;
        }

        return (int) $etablissement;
    }
}
