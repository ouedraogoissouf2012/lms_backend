<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;

/**
 * Les matières de « Mes notes » (`GET /my-grades`), par type d'école (D72, E4a,
 * ADR-EI-01 « même route, autre source »).
 *
 * - École KLASSCI ({@see KlassciMatieresDesNotes}) : l'identité KLASSCI de la
 *   matière, et son nom chez KLASSCI.
 * - École créée depuis le LMS ({@see LocalMatieresDesNotes}) : l'id local de la
 *   matière, et son libellé dans l'établissement. Aucun appel à KLASSCI.
 *
 * Liée par `EvaluationServiceProvider` au seul `RosterAuthorityFactory::sourceForCurrentTenant`
 * (`bind`, jamais `singleton`). Le contrôleur la reçoit par sa MÉTHODE : Laravel garde
 * le contrôleur sur l'objet Route.
 */
interface MatieresDesNotes
{
    /** Vrai : l'élève est refusé avant toute lecture (401 `klassci_token_missing`). */
    public function refuse(User $eleve): bool;

    /** La clé de regroupement d'une note : un id de matière dans l'espace de l'école, 0 sans matière. */
    public function cleDe(Evaluation $evaluation): int;

    /**
     * @param  list<int>  $cles
     * @return array<int, string> clé => nom
     */
    public function noms(User $eleve, array $cles): array;
}
