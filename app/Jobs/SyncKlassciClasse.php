<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Institution;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\Seances\Sync\SyncTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Synchronise une classe KLASSCI en arrière-plan.
 *
 * ## Pourquoi ce job existe
 *
 * `TeachingSeancesFetcher` appelait `ClasseSyncService::syncClasseById()` — donc
 * `GET classes/{id}` — **dans la boucle sur les séances**, en synchrone. Son
 * commentaire disait pourquoi : « Synchroniser la classe pour les notifications
 * futures ». Du travail destiné à un besoin futur, exécuté sur le chemin critique
 * d'un utilisateur présent.
 *
 * Le retour n'était même pas consommé : effet de bord pur.
 *
 * ## Ce que le report change
 *
 * L'enseignant obtient ses séances sans attendre, et le travail garde la latitude
 * d'un worker — réessais, temporisation, reprise quand KLASSCI redevient
 * joignable. C'est le principe : **un job peut attendre, un humain non**.
 *
 * Accessoirement, cela supprime une rafale d'appels séquentiels
 * (`classes/103`, `104`, `105`, `106` dans la même seconde) qui arme le filtre
 * anti-abus de l'hébergement de KLASSCI, mesuré le 2026-09-04.
 *
 * ## Le jeton ne voyage pas
 *
 * Les jobs sont sérialisés dans la table `jobs`. Y placer le jeton KLASSCI
 * l'écrirait **en clair** en base. Le job transporte donc l'identifiant de
 * l'utilisateur et relit le jeton depuis lui à l'exécution.
 *
 * ## Le tenant est posé explicitement
 *
 * `ClasseSyncService` s'appuie sur `TenantManager::getResolved()`, qui lève une
 * exception sans tenant. Un job ne traverse aucun middleware : il doit donc entrer
 * dans le contexte lui-même, via {@see SyncTenantContext} (#679).
 *
 * Verrouille par le test de fonctionnalite
 * TeachingSeancesDeferredClasseSyncTest (tests/Feature/LMS/Seances).
 */
final class SyncKlassciClasse implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Trois tentatives espacées : l'échec attendu est une indisponibilité passagère
     * de KLASSCI, pas une erreur de programmation. Un backoff croissant évite en
     * outre de rajouter des connexions rapides à une cible qui filtre sur le volume.
     *
     * @var int
     */
    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [60, 300];

    public function __construct(
        public readonly int $klassciClasseId,
        public readonly int $userId,
        public readonly int $institutionId,
    ) {
        $this->onQueue('low');
    }

    public function handle(
        ClasseSyncService $classeSync,
        SyncTenantContext $tenant,
        LoggerInterface $logger,
    ): void {
        $user = User::withoutGlobalScopes()->find($this->userId);

        if (! $user instanceof User || ! is_string($user->klassci_token) || $user->klassci_token === '') {
            // Le jeton a expiré ou le compte a disparu depuis la mise en file.
            // Rien à réessayer : ce n'est pas une panne, c'est une course normale.
            $logger->info('Synchronisation classe abandonnée — jeton KLASSCI absent', [
                'klassci_classe_id' => $this->klassciClasseId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        if (! Institution::withoutGlobalScopes()->whereKey($this->institutionId)->exists()) {
            $logger->warning('Synchronisation classe abandonnée — institution introuvable', [
                'klassci_classe_id' => $this->klassciClasseId,
                'institution_id' => $this->institutionId,
            ]);

            return;
        }

        $tenant->enter($this->institutionId);

        $classeSync->syncClasseById($this->klassciClasseId, $user->klassci_token);
    }

    /**
     * Un job de synchronisation qui échoue en silence laisse le miroir local
     * incomplet sans que personne ne le sache — exactement le genre de dette
     * invisible qui se paie plus tard.
     */
    public function failed(Throwable $exception): void
    {
        app(LoggerInterface::class)->error('Synchronisation classe KLASSCI définitivement échouée', [
            'klassci_classe_id' => $this->klassciClasseId,
            'user_id' => $this->userId,
            'institution_id' => $this->institutionId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
