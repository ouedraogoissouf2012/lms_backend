<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;
use App\Models\Matiere;
use App\Models\TrainingSession;
use App\Models\User;

/**
 * Les classes de l'établissement, lues dans les tables LOCALES, pour une école
 * créée depuis le LMS (lot 3a).
 *
 * ## Qui lit quoi
 *
 * La grille de la fiche ({@see ClassesAtteintes}) : la gestion lit toutes les
 * classes, un enseignant les siennes, un apprenant celles où son inscription est
 * active. Chaque classe porte son EFFECTIF, jamais sa liste d'élèves
 * (GHSA-gg7j).
 *
 * ## Ce que porte chaque classe
 *
 * - `id` : l'id LOCAL, celui que lisent les portes de la fiche. Dans une école
 *   basculée, c'est ce qui accorde la liste d'administration et la fiche (dette
 *   de la bascule, ADR-EI-01).
 * - `name` et `libelle` : le libellé. Le front lit l'un ou l'autre selon l'écran.
 * - `filiere`, `niveau` : `null`, aucun modèle local ne les porte.
 * - `is_active` : la Période de la classe, publiée, et ouverte aux inscriptions
 *   ou en cours ({@see TrainingSession::estActive()}) ; `null` sans Période.
 * - `places_occupees` : les inscriptions ACTIVES (D57) d'élèves de
 *   l'établissement. `places_totales` : `null`, aucune capacité n'est tenue.
 * - `nb_matieres` : le programme, en matières de l'établissement.
 *
 * Les filtres `filiere_id`, `niveau_id` et `annee_id` sont sans objet ici : aucun
 * n'est sélectionnable, puisque les filières et les niveaux locaux sont vides.
 *
 * Bornage EXPLICITE partout : le scope global est fail-open sans tenant.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
final class LocalInstitutionClassesReader implements InstitutionClassesReader
{
    /**
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    public function list(User $user, array $filtres): array
    {
        $institution = $user->institution_id;

        if ($institution === null) {
            return ['success' => true, 'data' => []];
        }

        $classes = ClassesAtteintes::par(Classe::query()->where('institution_id', $institution), $user, $institution)
            ->with(['trainingSession' => static function ($periode) use ($institution): void {
                $periode->where('institution_id', $institution);
            }])
            ->withCount([
                'etudiantsActifs as places_occupees' => static function ($inscrits) use ($institution): void {
                    $inscrits->whereIn('users.id', User::query()->where('institution_id', $institution)->select('id'));
                },
                'matieres as nb_matieres' => static function ($programme) use ($institution): void {
                    $programme->whereIn('classe_matiere.matiere_id', Matiere::query()->where('institution_id', $institution)->select('id'));
                },
            ])
            ->orderBy('libelle')
            ->orderBy('id')
            ->get();

        return [
            'success' => true,
            'data' => array_values($classes->map(fn (Classe $classe): array => $this->ligne($classe))->all()),
        ];
    }

    /**
     * @return array{id: int, name: string, libelle: string, code: string|null, filiere: null, niveau: null, is_active: bool|null, places_occupees: int, places_totales: null, nb_matieres: int}
     */
    private function ligne(Classe $classe): array
    {
        $periode = $classe->trainingSession;
        $compte = $classe->getAttributes();

        return [
            'id' => $classe->id,
            'name' => $classe->libelle,
            'libelle' => $classe->libelle,
            'code' => $classe->code,
            'filiere' => null,
            'niveau' => null,
            'is_active' => $periode instanceof TrainingSession ? $periode->estActive() : null,
            'places_occupees' => is_numeric($compte['places_occupees'] ?? null) ? (int) $compte['places_occupees'] : 0,
            'places_totales' => null,
            'nb_matieres' => is_numeric($compte['nb_matieres'] ?? null) ? (int) $compte['nb_matieres'] : 0,
        ];
    }
}
