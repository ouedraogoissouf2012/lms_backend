<?php

declare(strict_types=1);

namespace App\Services\Attendances;

use App\Models\User;

/**
 * La séance que désigne la liste quand aucune ligne locale ne la porte (#1098).
 *
 * Les présences sont attachées à la ligne locale d'une séance. Une séance d'école
 * KLASSCI vient de l'emploi du temps : tant qu'aucune ligne ne la représente, elle n'a
 * aucune présence, mais elle existe. Ce port la décrit, par école (ADR-EI-01, lié dans
 * `CatalogueServiceProvider`, reçu par la MÉTHODE du contrôleur) :
 *
 * - école KLASSCI : l'emploi du temps, comme le détail ({@see KlassciSeanceSansLigneLocale}) ;
 * - école autonome : rien, ses séances sont toutes des lignes ({@see LocalSeanceSansLigneLocale}).
 */
interface SeanceSansLigneLocale
{
    /**
     * `null` : la séance est introuvable pour cet appelant.
     *
     * @return array{klassci_seance_id: int, enseignant_nom: string|null, matiere_nom: string|null, date: string|null}|null
     */
    public function decrire(int $seanceId, User $user): ?array;
}
