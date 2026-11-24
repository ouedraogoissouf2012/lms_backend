<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;

/**
 * Le contrat d'une désignation de séance : la séance de l'école du compte que
 * désigne un identifiant, ou null. Les services qui agissent sur une séance
 * (suppression, masquage, présences de la visio, désactivation) le reçoivent de
 * leur appelant.
 *
 * Deux implémentations, une par espace d'identifiants (ADR-1154-01) :
 * - {@see LocalSeanceDesignee} : l'id local seulement, la porte locale et l'école
 *   autonome ;
 * - {@see KlassciSeanceDesigneeStricte} : l'id KLASSCI seulement, l'école KLASSCI.
 *
 * Les routes la reçoivent sous {@see SeanceDesigneeStrictement}, liée au mode de
 * l'école par `CatalogueServiceProvider::SOURCES`, par leur MÉTHODE (Laravel garde le
 * contrôleur sur l'objet Route). L'ancienne désignation sur les deux espaces,
 * le local d'abord (#933), est retirée par #1566 : sur une collision, elle désignait
 * la mauvaise séance.
 */
interface SeanceDesignee
{
    /** La séance de l'école du compte que désigne l'identifiant reçu, ou null. */
    public function pour(int $seanceId, User $user): ?Seance;
}
