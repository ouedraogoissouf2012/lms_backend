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
 * Synchronise les classes d'un enseignant en arrière-plan, à sa connexion (#712).
 *
 * ## Le défaut corrigé
 *
 * `ClasseSyncService::syncUserClasses()` n'était appelée par PERSONNE : ses
 * seules mentions dans le dépôt étaient des `@see` de docblocks. Le lot #740 y
 * avait branché l'amorçage du miroir `classe_matiere`, avec un test dédié — qui
 * appelait la méthode DIRECTEMENT. Le test prouvait que la méthode fonctionne,
 * jamais qu'elle est atteinte.
 *
 * Mesure en production le 2026-09-08 : `classe_matiere` à 0 ligne, et « Mes
 * Classes » désespérément vide malgré un correctif déployé et vert en CI.
 * `KlassciEnrollmentSource` résout les classes PAR ce miroir : sans lui, la
 * chaîne s'arrête au dernier maillon.
 *
 * ## Pourquoi un job, et pas un appel au login
 *
 * `syncUserClasses()` fait `GET /classes` puis un pool `GET classes/{id}` par
 * classe. C'est exactement le travail que {@see SyncKlassciClasse} a été créé
 * pour sortir du chemin critique — son docblock le dit sans détour : « du
 * travail destiné à un besoin futur, exécuté sur le chemin critique d'un
 * utilisateur présent ». On ne le remet pas dans le login.
 *
 * ## Le jeton ne voyage jamais dans le payload
 *
 * Il y serait sérialisé en clair dans la table `jobs`, lisible par quiconque
 * accède à la base. Le job ne transporte que des identifiants et relit le jeton
 * depuis l'utilisateur au moment de s'exécuter — même patron que
 * {@see SyncKlassciClasse}.
 *
 * Vérifié par tests/Feature/Jobs/SyncUserClassesTest.php.
 */
final class SyncUserClasses implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Trois tentatives espacées : l'échec attendu est une indisponibilité
     * passagère de KLASSCI, pas une erreur de programmation.
     *
     * @var int
     */
    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [60, 300];

    public function __construct(
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
            $logger->info('Synchronisation classes abandonnée — jeton KLASSCI absent', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        if (! Institution::withoutGlobalScopes()->whereKey($this->institutionId)->exists()) {
            $logger->warning('Synchronisation classes abandonnée — institution introuvable', [
                'user_id' => $this->userId,
                'institution_id' => $this->institutionId,
            ]);

            return;
        }

        $tenant->enter($this->institutionId);

        $classeSync->syncUserClasses($user->klassci_token, (string) $user->role);
    }

    public function failed(Throwable $exception): void
    {
        app(LoggerInterface::class)->error('Synchronisation classes KLASSCI définitivement échouée', [
            'user_id' => $this->userId,
            'institution_id' => $this->institutionId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
