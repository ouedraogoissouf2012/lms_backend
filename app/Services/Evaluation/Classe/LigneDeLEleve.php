<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Enums\EtatDeLaCase;
use App\Services\Evaluation\Student\Moyennes;

/**
 * #1676 : la ligne d'un élève dans le bilan de sa classe. Les règles de « Mes notes » : la moyenne
 * d'une matière est pondérée par le coefficient de chaque évaluation, la moyenne générale par le
 * coefficient de la matière ; rien à moyenner, `null`.
 *
 * Une évaluation sans note finale de l'élève est une note MANQUANTE : elle n'entre dans aucune
 * moyenne (jamais un 0 fabriqué), et elle est nommée dans `notes_manquantes`.
 *
 * #1698 : la ligne du carnet de notes, `cellules` : une case par évaluation, dans l'ordre des
 * colonnes, avec son état ({@see EtatDeLaCase}) ; seule une case `rendu` porte une note.
 */
final class LigneDeLEleve
{
    /**
     * @param  array<int, float>  $coefficients  coefficient de la matière (id local), dans la classe
     * @return array{compte_id: int|null, nom: string, moyenne_generale: float|null, rang: int|null, matieres: list<array{matiere_id: int|null, moyenne: float|null}>, notes: list<array{evaluation_id: int, note: float}>, notes_manquantes: list<int>, cellules: list<array{evaluation_id: int, etat: string, note: float|null}>}
     */
    public static function calculer(EleveDeLaClasse $eleve, NotesDeLaClasse $notes, array $coefficients): array
    {
        [$parMatiere, $notees, $manquantes, $cellules] = self::notes($eleve, $notes);

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
            'cellules' => $cellules,
        ];
    }

    /**
     * @return array{0: array<int, list<array{0: float, 1: float}>>, 1: list<array{evaluation_id: int, note: float}>, 2: list<int>, 3: list<array{evaluation_id: int, etat: string, note: float|null}>}
     */
    private static function notes(EleveDeLaClasse $eleve, NotesDeLaClasse $notes): array
    {
        $parMatiere = [];
        $notees = [];
        $manquantes = [];
        $cellules = [];
        foreach ($notes->evaluations as $evaluation) {
            $id = (int) $evaluation->id;
            // #1698 (C2) : une dispense sort l'évaluation de la ligne : ni note, ni note manquante.
            if ($notes->estDispense($eleve, $id)) {
                $cellules[] = ['evaluation_id' => $id, 'etat' => EtatDeLaCase::Dispense->value, 'note' => null];

                continue;
            }
            $copie = $notes->copieDe($eleve, $id);
            if ($copie === null || ! is_numeric($copie->note_sur_20)) {
                $manquantes[] = $id;
                $cellules[] = ['evaluation_id' => $id, 'etat' => $notes->etatSansNote($eleve, $id)->value, 'note' => null];

                continue;
            }

            $note = (float) $copie->note_sur_20;
            $notees[] = ['evaluation_id' => $id, 'note' => $note];
            $cellules[] = ['evaluation_id' => $id, 'etat' => EtatDeLaCase::Rendu->value, 'note' => $note];
            $coefficient = (float) $evaluation->coefficient;
            $parMatiere[$notes->matieres[$id] ?? 0][] = [$note, $coefficient];
        }

        return [$parMatiere, $notees, $manquantes, $cellules];
    }
}
