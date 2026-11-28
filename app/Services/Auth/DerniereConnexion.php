<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * #1704 : la date de la dernière connexion réussie d'un compte (`users.last_login_at`), le signal
 * « sans connexion » de l'alerte de décrochage. Seul écrivain de la colonne, appelé par les deux
 * connexions réussies de {@see LoginOrchestrator} (locale, KLASSCI).
 *
 * Écriture directe, hors modèle : une connexion n'est pas une modification du compte. Ni
 * `updated_at`, ni les observateurs du modèle (audit des modifications, synchronisations) ne
 * sont touchés. Le compte en mémoire reçoit la même date, sans être marqué modifié.
 */
final class DerniereConnexion
{
    public function marquer(User $compte): void
    {
        $maintenant = Carbon::now();

        User::query()->withoutGlobalScopes()->whereKey($compte->getKey())->toBase()->update(['last_login_at' => $maintenant]);
        $compte->setAttribute('last_login_at', $maintenant);
        $compte->syncOriginalAttribute('last_login_at');
    }
}
