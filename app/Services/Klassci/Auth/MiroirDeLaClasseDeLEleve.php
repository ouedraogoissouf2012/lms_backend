<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Jobs\SyncUserClasses;
use App\Models\Classe;
use App\Models\User;
use App\Support\Logging\CaughtExceptionLog;
use Throwable;

/**
 * Demande le miroir local de la classe KLASSCI d'un élève, s'il manque (#886).
 *
 * Sans classe miroir dans `classes`, `KlassciEnrollmentSource::localClasseIdsFor`
 * ne traduit pas `user_classes.klassci_classe_id` et rend `[]`. Ce service
 * n'écrit PAS `classes` : il demande le job existant {@see SyncUserClasses},
 * dont `ClasseSyncService` est le seul écrivain (ADR-878-01, ADR-886-01).
 *
 * - Le miroir est cherché dans l'école de l'élève, par un filtre explicite : au
 *   login, aucun tenant n'est résolu et le scope global est ouvert.
 * - Un miroir présent : rien, pour ne pas rejouer une synchro à chaque login
 *   (le filtre anti-abus de KLASSCI, mesuré le 04/09).
 * - Le job part APRÈS le commit : le login écrit le compte dans une transaction,
 *   et le job relit le compte et son jeton.
 * - Un échec ici ne casse jamais le login : il est journalisé.
 */
final class MiroirDeLaClasseDeLEleve
{
    public function __construct(private readonly CaughtExceptionLog $journal) {}

    public function demanderSiAbsent(User $eleve, int $klassciClasseId): void
    {
        $ecole = $eleve->institution_id;

        if (! is_int($ecole)) {
            return;
        }

        try {
            $present = Classe::query()
                ->withoutGlobalScope('institution')
                ->where('institution_id', $ecole)
                ->where('klassci_id', $klassciClasseId)
                ->exists();

            if (! $present) {
                SyncUserClasses::dispatch($eleve->id, $ecole)->afterCommit();
            }
        } catch (Throwable $e) {
            $this->journal->error('Demande du miroir de la classe de l\'élève en échec', $e, [
                'user_id' => $eleve->id,
                'klassci_classe_id' => $klassciClasseId,
            ]);
        }
    }
}
