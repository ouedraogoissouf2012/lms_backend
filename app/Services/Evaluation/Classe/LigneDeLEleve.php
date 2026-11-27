<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Services\Evaluation\Student\Moyennes;

/**
 * #1676 : la ligne d'un élève dans le bilan de sa classe. Les règles de « Mes notes » : la moyenne
 * d'une matière est pondérée par le coefficient de chaque évaluation, la moyenne générale par le
 * coefficient de la matière ; rien à moyenner, `null`.
 *
 * Une évaluation sans note finale de l'élève est une note MANQUANTE : elle n'entre dans aucune
 * moyenne (jamais un 0 fabriqué), et elle est nommée dans `notes_manquantes`.
 */
final class LigneDeLEleve
{
    /**
     * @param  array<int, float>  $coefficients  coefficient de la matière (id local), dans la classe
     * @return array{compte_id: int|null, nom: string, moyenne_generale: float|null, rang: int|null, matieres: list<array{matiere_id: int|null, moyenne: float|null}>, notes: list<array{evaluation_id: int, note: float}>, notes_manquantes: list<int>}
     */
    public static function calculer(EleveDeLaClasse $eleve, NotesDeLaClasse $notes, array $coefficients): array
    {
        [$parMatiere, $notees, $manquantes] = self::notes($eleve, $notes);

        $matieres = [];
        $generale = [];
        foreach ($parMatiere as $matiere => $comptees) {
            $moyenne = Moyennes::ponderee($comptees);
            $matieres[] = ['matiere_id' => $matiere === 0 ? null : $matiere, 'moyenne' => $moyenne];
            if ($moyenne !== null) {
                $generale[] = [$moyenne, $coefficients[$matiere] ?? 1.0];
            }
        }

        return [
            'compte_id' => $eleve->compte,
            'nom' => $eleve->nom,
            'moyenne_generale' => Moyennes::ponderee($generale),
            'rang' => null,
            'matieres' => $matieres,
            'notes' => $notees,
            'notes_manquantes' => $manquantes,
        ];
    }

    /**
     * @return array{0: array<int, list<array{0: float, 1: float}>>, 1: list<array{evaluation_id: int, note: float}>, 2: list<int>}
     */
    private static function notes(EleveDeLaClasse $eleve, NotesDeLaClasse $notes): array
    {
        $parMatiere = [];
        $notees = [];
        $manquantes = [];
        foreach ($notes->evaluations as $evaluation) {
            $id = (int) $evaluation->id;
            $copie = $notes->copieDe($eleve, $id);
            if ($copie === null || ! is_numeric($copie->note_sur_20)) {
                $manquantes[] = $id;

                continue;
            }

            $note = (float) $copie->note_sur_20;
            $notees[] = ['evaluation_id' => $id, 'note' => $note];
            $coefficient = is_numeric($evaluation->coefficient) ? (float) $evaluation->coefficient : 1.0;
            $parMatiere[$notes->matieres[$id] ?? 0][] = [$note, $coefficient];
        }

        return [$parMatiere, $notees, $manquantes];
    }
}
