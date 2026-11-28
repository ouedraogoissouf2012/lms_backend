<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tuteur;
use App\Models\User;

/**
 * #1657 : la purge d'un compte (suppression DÉFINITIVE) emporte son dossier d'élève et ses liens de tuteur (clés en
 * cascade). Un tuteur resté sans aucun élève dans l'école est alors une donnée inutile (loi 2013-450) : il part aussi.
 * La mise en corbeille (suppression logique) ne touche à rien : l'élève peut revenir.
 *
 * Convention `bootXxx`, comme `Auditable` : pas d'observer dispersé dans un fournisseur.
 */
trait RetireLesTuteursSansEleve
{
    public static function bootRetireLesTuteursSansEleve(): void
    {
        static::forceDeleted(static function (User $compte): void {
            if (! is_int($compte->institution_id)) {
                return;
            }

            Tuteur::query()->withoutGlobalScopes()
                ->where('institution_id', $compte->institution_id)
                ->whereDoesntHave('dossiers')
                ->get()
                ->each(static fn (Tuteur $tuteur) => $tuteur->delete());
        });
    }
}
