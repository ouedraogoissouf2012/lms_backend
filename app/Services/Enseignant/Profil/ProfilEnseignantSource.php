<?php

declare(strict_types=1);

namespace App\Services\Enseignant\Profil;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * Le profil d'un enseignant (#1666), par école. Deux sources, choisies par le mode DÉCLARÉ de l'école au point de
 * liaison (`CatalogueServiceProvider::SOURCES`) et reçues par la MÉTHODE du contrôleur (ADR-EI-01) :
 * {@see LocalProfilEnseignant} tient le profil ; {@see KlassciProfilEnseignant} le laisse à KLASSCI, qui ne relaie que
 * le matricule, la spécialité et le statut (dans la liste des enseignants).
 */
interface ProfilEnseignantSource
{
    /**
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    public function lire(User $enseignant): array;

    /**
     * @param  array{matricule: ?string, specialite: ?string, titre: ?string, grade: ?string, diplome: ?string, universite: ?string, date_debut: ?string, statut: ?string, charge_hebdo_max: ?int}  $champs
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    public function ecrire(User $enseignant, array $champs): array;
}
