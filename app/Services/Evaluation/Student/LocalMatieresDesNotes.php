<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\Matiere;
use App\Models\User;

/**
 * Les matières de « Mes notes » dans une école créée depuis le LMS (D72, E4a).
 *
 * - Aucun refus : l'élève n'a pas d'identité KLASSCI, et n'en a pas besoin (C23).
 * - Regroupement par la matière LOCALE de l'évaluation (`matiere_id`), 0 sans
 *   matière. Avant, la clé `klassci_matiere_id`, nulle ici, fondait toutes les
 *   matières sous 0.
 * - Noms : le libellé des matières de l'établissement de l'élève, borné
 *   explicitement (le scope global est fail-open sans tenant). Aucun appel à
 *   KLASSCI, même pour un compte porteur d'un jeton.
 */
final class LocalMatieresDesNotes implements MatieresDesNotes
{
    public function refuse(User $eleve): bool
    {
        return false;
    }

    public function cleDe(Evaluation $evaluation): int
    {
        return (int) ($evaluation->matiere_id ?? 0);
    }

    public function noms(User $eleve, array $cles): array
    {
        $etablissement = $eleve->institution_id;
        if (! is_int($etablissement) || $cles === []) {
            return [];
        }

        $noms = [];
        $matieres = Matiere::query()->withoutGlobalScope('institution')
            ->where('institution_id', $etablissement)
            ->whereKey($cles)
            ->get(['id', 'libelle']);
        foreach ($matieres as $matiere) {
            $noms[$matiere->id] = $matiere->libelle;
        }

        return $noms;
    }
}
