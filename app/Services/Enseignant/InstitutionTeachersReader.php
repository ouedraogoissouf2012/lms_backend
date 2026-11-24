<?php

declare(strict_types=1);

namespace App\Services\Enseignant;

use App\Models\User;

/**
 * Les enseignants de l'établissement, pour l'annuaire et pour la liste de
 * l'administration (ADR-EI-01, lot 3b).
 *
 * Deux sources, choisies par le mode DÉCLARÉ de l'école au point de liaison :
 * KLASSCI ({@see KlassciInstitutionTeachersReader}) et le LMS
 * ({@see LocalInstitutionTeachersReader}). Chacune applique à SA lecture la
 * règle unique de visibilité, `TeacherDirectoryVisibility` (D120). Le
 * contrôleur reçoit la source par sa MÉTHODE.
 */
interface InstitutionTeachersReader
{
    /**
     * `GET /proxy/enseignants` : la réponse servie, `{success, data, meta?}`.
     *
     * @return array<array-key, mixed>
     */
    public function annuaire(User $lecteur): array;

    /**
     * `GET /lms/enseignants` : `success` faux et un `message` quand la source
     * refuse, sinon les lignes servies et leur `meta`.
     *
     * @return array{success: bool, message?: string, data: array<array-key, mixed>, meta: array<string, mixed>}
     */
    public function listeEnrichie(User $lecteur, bool $details): array;

    /**
     * L'`id` que CETTE source émet pour ce compte dans l'annuaire, ou `null` s'il n'y figure
     * pas en enseignant (#1536). Le compte connecté s'y reconnaît sans connaître le mode (#805).
     */
    public function idDansLAnnuaire(User $compte): ?int;
}
