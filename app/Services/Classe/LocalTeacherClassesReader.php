<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;
use App\Models\Matiere;
use App\Models\User;

/**
 * « Mes classes », lues dans le programme LOCAL de l'établissement.
 *
 * ## Ce que la source KLASSCI ne pouvait pas rendre
 *
 * Son repli local écarte toute classe sans `klassci_id`
 * (`TeacherClassesQueryService::fromLocalMirror`) : il rend l'espace KLASSCI,
 * celui que le front fusionne avec `proxy/classes`. Dans une école créée
 * depuis le LMS, aucune classe n'a cet identifiant, et la liste restait vide.
 * Ici l'espace est LOCAL, le seul qui existe dans cet établissement.
 *
 * ## Les classes de l'APPELANT, coordinateur compris
 *
 * Celles où il est désigné formateur d'au moins une matière
 * (`classe_matiere.enseignant_id`) — la règle de « Mes matières » au lot 1.
 *
 * ## Deux effectifs, deux sens
 *
 * - `effectif_actuel` : les inscriptions ACTIVES (D57), un fait mesuré.
 * - `effectif` : `null`. La colonne n'est tenue que par le synchroniseur
 *   KLASSCI et vaut 0 pour une classe locale ; l'exposer afficherait un zéro
 *   faux (même règle que `ClassesLocalesListTest`).
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
final class LocalTeacherClassesReader implements TeacherClassesReader
{
    /**
     * @return list<array{id: int, libelle: string|null, code: string|null, effectif: int|null, effectif_actuel: int|null}>
     */
    public function listFor(User $teacher): array
    {
        $institution = $teacher->institution_id;

        if ($institution === null) {
            return [];
        }

        // Bornage EXPLICITE, sur la classe comme sur la matière : le scope
        // global est fail-open sans tenant résolu.
        $classes = Classe::query()
            ->where('institution_id', $institution)
            ->whereHas('matieres', static function ($programme) use ($teacher, $institution): void {
                $programme->where('classe_matiere.enseignant_id', $teacher->id)
                    ->whereIn('classe_matiere.matiere_id', Matiere::query()
                        ->where('institution_id', $institution)
                        ->select('id'));
            })
            ->withCount(['etudiantsActifs as effectif_actuel'])
            // L'ordre est un CONTRAT : la liste se rend en cartes, qui ne
            // doivent pas sauter d'un chargement à l'autre.
            ->orderBy('libelle')
            ->orderBy('id')
            ->get();

        return array_values($classes->map(static function (Classe $classe): array {
            $actuel = $classe->getAttributes()['effectif_actuel'] ?? null;

            return [
                'id' => $classe->id,
                'libelle' => $classe->libelle,
                'code' => $classe->code,
                'effectif' => null,
                'effectif_actuel' => is_numeric($actuel) ? (int) $actuel : 0,
            ];
        })->all());
    }
}
