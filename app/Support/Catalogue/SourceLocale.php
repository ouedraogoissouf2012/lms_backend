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
 * Dette tracée : `LocalTeacherDirectory` et `LocalInstitutionTeachersReader`
 * écrivent encore cette valeur en clair. Leur migration vient après la fusion de
 * #1027.
 */
final class SourceLocale
{
    public const LMS_LOCAL = 'lms_local';
}
