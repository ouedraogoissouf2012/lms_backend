<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Enums\RecordingFailureReason;
use App\Models\Classe;
use App\Models\Matiere;
use App\Models\Seance;
use App\Services\Roster\RosterAuthorityFactory;

/**
 * Où ranger l'enregistrement d'une séance : sa matière, sa classe, son enseignant (#1483).
 *
 * Une séance KLASSCI les désigne dans l'espace KLASSCI (`klassci_*`), une séance locale par
 * ses colonnes locales (`matiere_id`, `classe_id`, `created_by`). L'implémentation se choisit
 * par le MODE de l'école, au seul {@see RosterAuthorityFactory} : jamais
 * par la nullité d'une colonne.
 *
 * Tout est lu dans l'école de la séance, explicitement : un job n'a pas de tenant.
 */
interface CiblesDeLaSeance
{
    /** La matière de la séance dans son école, ou le motif nommé de son absence. */
    public function matiere(Seance $seance): Matiere|RecordingFailureReason;

    /** La classe de la séance dans son école ; `null` : aucune contrainte de classe. */
    public function classe(Seance $seance): ?Classe;

    /**
     * Les `users.id` LOCAUX de l'enseignant de la séance.
     *
     * - `null` : aucun enseignant déclaré, pas de contrainte ;
     * - `[]` : déclaré mais introuvable : AUCUN candidat, jamais une recherche élargie (#707) ;
     * - une liste : les comptes correspondants.
     *
     * @return list<int>|null
     */
    public function enseignants(Seance $seance): ?array;
}
