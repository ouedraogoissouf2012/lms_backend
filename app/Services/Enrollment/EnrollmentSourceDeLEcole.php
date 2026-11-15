<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Classe;
use App\Models\User;
use App\Services\Roster\RosterAuthorityFactory;

/**
 * La source des inscriptions de l'école du compte, choisie à CHAQUE appel par son
 * autorité (D410, ADR-EI-01 « même port, autre source ») :
 *
 * - école KLASSCI : {@see CompositeEnrollmentSource} (le pivot actif, puis le repli
 *   sur le miroir `user_classes`) ;
 * - école autonome : {@see LocalEnrollmentSource}, le pivot de l'établissement SEUL.
 *   Le miroir KLASSCI gardé d'avant une bascule n'admet personne (D57) ;
 * - sans tenant : la source KLASSCI, comme avant. Hors requête, entrer le tenant
 *   de l'école avant de lire une admission.
 *
 * Pourquoi un délégant, et pas une liaison par école : les lecteurs reçoivent le port
 * par leur CONSTRUCTEUR, et ils sont tenus par des contrôleurs que Laravel garde sur
 * l'objet Route (`ChapterOriginalController` tient `ChapterReadGate`). Une source
 * choisie à la construction resterait celle de la première école servie (règle 3).
 * Les deux méthodes suivent la même autorité : jamais un port à moitié choisi.
 */
final class EnrollmentSourceDeLEcole implements EnrollmentSource
{
    public function __construct(private readonly RosterAuthorityFactory $autorites) {}

    public function localClasseIdsFor(User $user): array
    {
        return $this->source()->localClasseIdsFor($user);
    }

    public function classeIdsForTeacher(User $teacher): array
    {
        return $this->source()->classeIdsForTeacher($teacher);
    }

    /**
     * Par l'établissement de la CLASSE, et non par le tenant : les avertissements
     * visio lisent ce sens depuis un job qui n'entre pas le tenant (C40, D454).
     * Une classe sans établissement : la source KLASSCI, comme sans tenant.
     */
    public function localStudentIdsOf(Classe $classe): array
    {
        $source = $this->autorites->sourceForInstitution(
            $classe->institution,
            CompositeEnrollmentSource::class,
            LocalEnrollmentSource::class,
        );

        return $source->localStudentIdsOf($classe);
    }

    private function source(): EnrollmentSource
    {
        return $this->autorites->sourceForCurrentTenant(CompositeEnrollmentSource::class, LocalEnrollmentSource::class);
    }
}
