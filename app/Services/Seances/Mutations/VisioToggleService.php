<?php

declare(strict_types=1);

namespace App\Services\Seances\Mutations;

use App\Exceptions\MissingKlassciTokenException;
use App\Http\Controllers\API\LMS\Concerns\FetchesSeanceDataFromKlassci;
use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\Notification\AsyncVisioNotificationDispatcher;
use App\Services\Seances\SeanceRestoreGuard;
use App\Services\Visio\Lifecycle\VisioToggleSource;
use App\Services\Visio\SecureVisioRoomIdGenerator;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * VisioToggleService — extrait initialement de
 * `LMSSeancesMutationController::toggleVisioSeance()`, depuis étoffé (#542).
 *
 * Active ou désactive la visioconférence pour une séance, met à jour
 * l'entrée locale `seances` et — quand l'activation a lieu — envoie les
 * notifications aux étudiants concernés via `NotificationService`.
 *
 * Workaround historique préservé : pas d'endpoint KLASSCI `GET /seances/{id}`,
 * donc les IDs de matière/classe/enseignant sont initialement null puis
 * éventuellement complétés par `getSeanceDataFromKlassci()`.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class VisioToggleService implements VisioToggleSource
{
    use FetchesSeanceDataFromKlassci;

    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly ClasseSyncService $classeSyncService,
        private readonly AsyncVisioNotificationDispatcher $notifications,
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Toggle la visio pour la séance donnée.
     *
     * Sans jeton KLASSCI : 401 motivé (#806), AVANT toute écriture. La séance
     * est cherchée — et créée si absente — sous son identifiant KLASSCI
     * (`restoreOrCreateVisio`) : c'est une opération KLASSCI. Une séance
     * d'école autonome naît avec `klassci_seance_id` nul ; la basculer ici
     * créerait une séance fantôme sous son identifiant local, et répondrait
     * « activée » sans avoir touché la vraie.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function toggle(
        int $seanceId,
        bool $enabled,
        ?string $visioType,
        User $user,
    ): array {
        $klassciToken = $user->klassci_token;
        if (! is_string($klassciToken) || $klassciToken === '') {
            return ['status' => 401, 'payload' => MissingKlassciTokenException::payload()];
        }

        try {
            $visioType = $visioType ?? 'jitsi';

            $this->logger->info('Toggle visio séance', [
                'klassci_seance_id' => $seanceId,
                'enabled' => $enabled,
                'visio_type' => $visioType,
                'user_id' => $user->id,
            ]);

            // WORKAROUND: pas d'endpoint GET /seances/{id} dans KLASSCI.
            // On crée/met à jour directement l'entrée locale ; les IDs
            // matière/classe/enseignant restent null tant que `getSeanceDataFromKlassci`
            // ne les complète pas, mais cela ne casse rien : les données réelles
            // viennent toujours de KLASSCI via /matieres/{id}.
            $this->logger->info('Création/MAJ entrée visio locale sans vérification KLASSCI', [
                'klassci_seance_id' => $seanceId,
                'note' => 'Workaround: endpoint GET /seances/{id} inexistant dans KLASSCI',
            ]);

            $etat = [
                'visio_enabled' => $enabled,
                'visio_type' => $enabled ? $visioType : null,
                'visio_status' => $enabled ? 'programmee' : null,
                'visio_active' => false,
                'updated_by' => $user->id,
            ];

            $visio = $this->restoreOrCreateVisio($seanceId, $etat, $enabled);

            if ($visio->wasRecentlyCreated) {
                $visio->created_by = $user->id;
                $visio->save();
            }

            $notificationsSent = $enabled ? $this->dispatchNotifications($seanceId, $visio, $klassciToken) : 0;

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => $enabled ? 'Visioconférence activée avec succès' : 'Visioconférence désactivée',
                    'data' => [
                        'seance_id' => $seanceId,
                        'visio_enabled' => $visio->visio_enabled,
                        'visio_type' => $visio->visio_type,
                        'visio_room_id' => $visio->visio_room_id,
                        'visio_active' => $visio->visio_active,
                        'notifications_sent' => $notificationsSent,
                        // Le jeton est garanti à ce point : la planification suit l'activation.
                        'notifications_queued' => $enabled,
                    ],
                ],
            ];
        } catch (Throwable $e) {
            $this->journal->error('Erreur toggle visio séance', $e, [
                'seance_id' => $seanceId,
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de l\'activation/désactivation de la visio',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * #542 — `updateOrCreate()` exclut implicitement les lignes soft-deletées
     * (SoftDeletingScope non retiré) : une resurrection de la même séance
     * après suppression violerait l'unique composite (klassci_seance_id,
     * institution_id) à la création. Lookup manuel `withTrashed()` + restore
     * explicite. Scope global `BelongsToInstitution` toujours actif (contexte
     * HTTP authentifié) — pas de `withoutGlobalScope('institution')` ici.
     *
     * Le `catch` reproduit le retry-sur-conflit natif de `updateOrCreate()`
     * (`Builder::createOrFirst()`, vendor Laravel) perdu en remplaçant
     * `updateOrCreate()` par ce lookup manuel : deux requêtes concurrentes
     * peuvent toutes deux trouver `null` puis tenter un INSERT — la
     * perdante violerait l'unique sans ce filet.
     *
     * Une séance CRÉÉE ici n'a ni classe, ni enseignant, ni matière KLASSCI (aucun
     * endpoint ne les donne). Une séance EXISTANTE garde les siens, que la
     * synchronisation a écrits : la bascule n'en change que l'état de la visio.
     *
     * @param  array<string, mixed>  $etat
     */
    private function restoreOrCreateVisio(int $seanceId, array $etat, bool $enabled): Seance
    {
        $visio = Seance::withTrashed()->where('klassci_seance_id', $seanceId)->first();

        if ($visio) {
            return $this->restoreThenUpdate($visio, $etat, $enabled);
        }

        try {
            return Seance::create([
                'klassci_seance_id' => $seanceId,
                'klassci_matiere_id' => null,
                'klassci_classe_id' => null,
                'klassci_enseignant_id' => null,
                'visio_room_id' => $enabled ? SecureVisioRoomIdGenerator::make() : null,
            ] + $etat);
        } catch (UniqueConstraintViolationException) {
            $visio = Seance::withTrashed()->where('klassci_seance_id', $seanceId)->firstOrFail();

            return $this->restoreThenUpdate($visio, $etat, $enabled);
        }
    }

    /**
     * Le salon n'est frappé que si la place est libre (`??=`, comme
     * `VisioActivationService::upsertVisio`) : celui que la séance a déjà publié
     * reste le sien.
     *
     * @param  array<string, mixed>  $etat
     */
    private function restoreThenUpdate(Seance $visio, array $etat, bool $enabled): Seance
    {
        SeanceRestoreGuard::restoreIfTrashed($visio);
        $visio->fill($etat);
        if ($enabled) {
            $visio->visio_room_id ??= SecureVisioRoomIdGenerator::make();
        } else {
            $visio->visio_room_id = null;
        }
        $visio->save();

        return $visio;
    }

    /**
     * Synchronise la classe puis envoie les notifications aux étudiants.
     */
    private function dispatchNotifications(int $seanceId, Seance $visio, string $klassciToken): int
    {
        if ($visio->klassci_classe_id !== null) {
            return $this->avertirDApresLaSeance($seanceId, $visio);
        }

        try {
            $seanceData = $this->getSeanceDataFromKlassci($seanceId, $klassciToken);

            if (! $seanceData) {
                return 0;
            }

            $visio->update([
                'klassci_matiere_id' => $seanceData['matiere_id'] ?? null,
                'klassci_classe_id' => $seanceData['classe_id'] ?? null,
                'klassci_enseignant_id' => $seanceData['enseignant_id'] ?? null,
                'matiere_nom' => $seanceData['matiere_nom'] ?? null,
                'enseignant_nom' => $seanceData['enseignant_nom'] ?? null,
            ]);

            if (isset($seanceData['classe_id'])) {
                $this->classeSyncService->syncClasseById(
                    (int) $seanceData['classe_id'],
                    $klassciToken,
                );
            }

            $this->notifications->queueScheduled($seanceId, [
                // L'id LOCAL de la séance : la clé de la garde d'idempotence (V1).
                'seance_locale_id' => $visio->id,
                'klassci_classe_id' => $seanceData['classe_id'] ?? null,
                'klassci_enseignant_id' => $seanceData['enseignant_id'] ?? null,
                'matiere_nom' => $seanceData['matiere_nom'] ?? 'Matière',
                'enseignant_nom' => $seanceData['enseignant_nom'] ?? 'Enseignant',
            ]);

            $this->logger->info('Notifications visio planifiées via toggleVisio', [
                'seance_id' => $seanceId,
            ]);

            return 0;
        } catch (Throwable $e) {
            $this->journal->error('Erreur envoi notifications via toggleVisio', $e, [
                'seance_id' => $seanceId,
            ]);

            return 0;
        }
    }

    /**
     * Une séance qui désigne sa classe (écrite par la synchronisation) : ses
     * élèves sont avertis d'après la séance elle-même, comme à l'activation
     * (`VisioActivationService`). La lecture KLASSCI ne sert qu'à une séance créée
     * par la bascule, sans classe.
     */
    private function avertirDApresLaSeance(int $seanceId, Seance $visio): int
    {
        try {
            $this->notifications->queueScheduled($seanceId, [
                // L'id LOCAL de la séance : la clé de la garde d'idempotence (V1).
                'seance_locale_id' => $visio->id,
                'klassci_classe_id' => $visio->klassci_classe_id,
                'klassci_enseignant_id' => $visio->klassci_enseignant_id,
                'matiere_nom' => $visio->matiere_nom ?? 'Matière',
                'enseignant_nom' => $visio->enseignant_nom ?? 'Enseignant',
            ]);
        } catch (Throwable $e) {
            $this->journal->error('Erreur envoi notifications via toggleVisio', $e, [
                'seance_id' => $seanceId,
            ]);
        }

        return 0;
    }
}
