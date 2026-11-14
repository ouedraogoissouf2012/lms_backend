<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Classe;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Evaluation\EvaluationsDeLaPage;
use App\Support\Catalogue\SourceLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Les matières de l'établissement, lues dans le catalogue LOCAL, pour une école
 * créée depuis le LMS (lot 3c).
 *
 * ## Qui lit quoi
 *
 * La grille de {@see MatieresAtteintes} : la gestion lit tout le catalogue, un
 * enseignant les matières où il est désigné, un apprenant celles de ses classes
 * à inscription active. `/admin/matieres` n'admet que la gestion, avant même ce
 * lecteur ; la grille s'y applique quand même : une seule règle, partout.
 *
 * ## Des ids LOCAUX
 *
 * `id` est `matieres.id`, celui que portent `lessons.matiere_id` et la page
 * d'une matière ; `classes[].id` est l'id local que rend `/proxy/classes`
 * (lot 3a). Le front compte les matières d'une classe en les croisant.
 *
 * ## Ce que le catalogue local ne porte pas
 *
 * Ni heures, ni séances par matière, ni filière, ni niveau, ni couleur :
 * - `heures_total` et `nb_seances_programmees` valent `null`, « inconnu »,
 *   jamais 0 (une séance locale ne porte que le NOM de sa matière) ;
 * - `nb_evaluations` compte toutes les évaluations de l'établissement dans la
 *   matière, en une requête groupée ({@see EvaluationsDeLaPage::compteParMatiere()},
 *   D72, E4c) ;
 * - `combinaisons` est vide : aucune filière ni aucun niveau local ;
 * - `couleur` est la couleur par défaut de l'écran, un réglage d'affichage.
 *
 * Les filtres `filiere_id` et `niveau_id` sont sans objet : rien ne les porte.
 *
 * Bornage EXPLICITE partout : le scope global est fail-open sans tenant.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
final class LocalInstitutionMatieresReader implements InstitutionMatieresReader
{
    /**
     * @param  array<string, mixed>  $filtres
     * @return array{success: true, data: list<array{id: int, nom: string, code: string|null, description: string|null, coefficient: int, classes: list<array{id: int, nom: string}>, source: string}>}
     */
    public function list(User $user, array $filtres): array
    {
        $institution = $user->institution_id;

        if (! is_int($institution)) {
            return ['success' => true, 'data' => []];
        }

        $matieres = $this->atteintes($user, $institution)
            ->with(['classes' => static function ($classes) use ($user, $institution): void {
                /** @var BelongsToMany<Classe, Matiere> $classes */
                self::classesAtteintes($classes, $user, $institution);
            }])
            ->get()
            ->all();

        return [
            'success' => true,
            'data' => array_values(array_map(static fn (Matiere $matiere): array => [
                'id' => $matiere->id,
                'nom' => $matiere->libelle,
                'code' => $matiere->code,
                'description' => $matiere->description,
                'coefficient' => $matiere->coefficient,
                'classes' => array_values(array_map(static fn (Classe $classe): array => [
                    'id' => $classe->id,
                    'nom' => $classe->libelle,
                ], $matiere->classes->all())),
                'source' => SourceLocale::LMS_LOCAL,
            ], $matieres)),
        ];
    }

    /**
     * @return array{matieres: array<int, array<string, mixed>>, statistiques: array<string, mixed>}
     */
    public function listForAdmin(User $user): array
    {
        $institution = $user->institution_id;
        $matieres = is_int($institution) ? $this->atteintes($user, $institution)->get()->all() : [];
        $lecons = is_int($institution) ? $this->leconsParMatiere($matieres, $institution) : [];
        $evaluations = is_int($institution)
            ? EvaluationsDeLaPage::compteParMatiere($institution, array_values(array_map(static fn (Matiere $m): int => $m->id, $matieres)), null)
            : [];

        $lignes = array_values(array_map(static fn (Matiere $matiere): array => [
            'id' => $matiere->id,
            'nom' => $matiere->libelle,
            'code' => $matiere->code ?? AdminMatierePresentation::SANS_VALEUR,
            'description' => $matiere->description,
            'coefficient' => $matiere->coefficient,
            'couleur' => AdminMatierePresentation::COULEUR_PAR_DEFAUT,
            'heures_total' => null,
            'nb_evaluations' => $evaluations[$matiere->id] ?? 0,
            'nb_seances_programmees' => null,
            'nb_lecons' => $lecons[$matiere->id] ?? 0,
            'combinaisons' => [],
        ], $matieres));

        return [
            'matieres' => $lignes,
            'statistiques' => AdminMatierePresentation::statistiques($lignes),
        ];
    }

    /**
     * Les classes de chaque matière où ce compte l'atteint, triées par libellé
     * puis par identifiant.
     *
     * @param  BelongsToMany<Classe, Matiere>  $classes
     */
    private static function classesAtteintes(BelongsToMany $classes, User $user, int $institution): void
    {
        MatieresAtteintes::classesAtteintes($classes->getQuery(), $user, $institution);
        $classes->orderBy('classes.libelle')->orderBy('classes.id');
    }

    /**
     * @return Builder<Matiere>
     */
    private function atteintes(User $user, int $institution): Builder
    {
        return MatieresAtteintes::par(Matiere::query()->where('institution_id', $institution), $user, $institution)
            ->orderBy('libelle')
            ->orderBy('id');
    }

    /**
     * Les leçons de chaque matière, tous statuts : ce que la page d'une matière
     * montre à la gestion ({@see MatiereLessonsAndStatsBuilder::lessonsForLocalMatiere()}).
     * Un compte par matière en UNE requête, quel que soit leur nombre.
     *
     * @param  array<int, Matiere>  $matieres
     * @return array<int, int>
     */
    private function leconsParMatiere(array $matieres, int $institution): array
    {
        if ($matieres === []) {
            return [];
        }

        $comptes = [];

        foreach (
            Lesson::query()
                ->where('institution_id', $institution)
                ->whereIn('matiere_id', array_map(static fn (Matiere $matiere): int => $matiere->id, $matieres))
                ->select('matiere_id')
                ->selectRaw('COUNT(*) as cnt')
                ->groupBy('matiere_id')
                ->get() as $ligne
        ) {
            $nombre = $ligne->getAttribute('cnt');

            if (is_int($ligne->matiere_id) && is_numeric($nombre)) {
                $comptes[$ligne->matiere_id] = (int) $nombre;
            }
        }

        return $comptes;
    }
}
