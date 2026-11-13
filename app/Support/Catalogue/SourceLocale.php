<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

/**
 * La marque qu'une ligne locale porte sur elle-même (D172). Dans une école créée
 * depuis le LMS, une carte de matière lue dans le LMS porte `source` avec cette
 * valeur : le client sait, sur la ligne, que ses ids sont LOCAUX. Aucun mode
 * d'école n'est exposé (D132 Q1). Dans une école créée depuis KLASSCI, la clé est
 * absente.
 *
 * La même marque sert, au niveau de la réponse (`meta.source`), la liste des
 * enseignants (`LocalInstitutionTeachersReader`), le repli local de l'annuaire
 * (`LocalTeacherDirectory`) et le tableau de bord de l'enseignant
 * (`LocalTeacherDashboardReader`). Une seule valeur, écrite ici.
 */
final class SourceLocale
{
    public const LMS_LOCAL = 'lms_local';
}
