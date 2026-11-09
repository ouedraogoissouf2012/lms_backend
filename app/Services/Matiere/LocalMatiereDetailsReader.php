<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Classe;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * La page d'une matière, lue dans le programme LOCAL de l'établissement.
 *
 * ## Un seul espace d'identifiants
 *
 * L'id de la route est LOCAL : dans un établissement sans KLASSCI, aucune
 * matière n'a de `klassci_id`, et il n'y a rien à traduire. Ce n'est pas
 * l'arbitrage à l'entrée qu'interdit ADR-760-01 : rien n'est deviné, la source
 * est choisie par le mode déclaré, au point de liaison.
 *
 * ## Qui lit quoi
 *
 * | Appelant | Matière | Classes rendues | Leçons |
 * |---|---|---|---|
 * | gestion (coordinateur, admin) | toute matière de l'établissement | toutes celles qui l'ont au programme | toutes |
 * | enseignant | celle où il est désigné formateur | celles où il l'enseigne | les SIENNES seulement (décision de l'utilisateur, D73) |
 * | apprenant | celle d'une classe où son inscription est ACTIVE | ces classes-là | publiées, de ces classes-là |
 *
 * Hors de ces cas, `null`, rendu en 404 : le refus ne dit pas si la matière
 * existe, ni dans quelle école.
 *
 * La source KLASSCI rend encore à l'enseignant les leçons de ses collègues :
 * l'aligner relève du lot qui borne les lectures de leçons, pas de celui-ci.
 *
 * ## Les compteurs qu'on ne connaît pas
 *
 * Séances : `null`. Une séance locale ne porte que le NOM de sa matière, en
 * texte (`LocalSeanceCreator`) ; compter par ce texte devinerait. Évaluations :
 * vides, provisoirement — jusqu'à la fonction qui les construit (D72).
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
final class LocalMatiereDetailsReader implements MatiereDetailsReader
{
    public function __construct(private readonly MatiereLessonsAndStatsBuilder $lessons) {}

    /**
     * @return array{matiere: array<string, mixed>, matiere_id_local: int|null, combinaisons: array<int, array<string, mixed>>, enseignants: array<int, array<string, mixed>>, lessons: array<int, array<string, mixed>>, seances_programmees: array<int, array<string, mixed>>, evaluations_programmees: array<int, array<string, mixed>>, classes_concernees: array<int, array{id: int, nom: string}>, statistiques: array<string, mixed>}|null
     */
    public function getDetailsForUser(int $matiereId, User $user): ?array
    {
        $institution = $user->institution_id;

        if (! is_int($institution)) {
            return null;
        }

        // Bornage EXPLICITE : le scope global est fail-open sans tenant.
        $matiere = Matiere::query()->where('institution_id', $institution)->whereKey($matiereId)->first();
        $classes = $matiere === null ? null : $this->classesVisibles($matiere, $user, $institution);

        if ($matiere === null || $classes === null) {
            return null;
        }

        $classeIds = array_map(static fn (Classe $classe): int => $classe->id, $classes);
        $lessons = $this->lessons->lessonsForLocalMatiere(
            $matiere->id,
            $user,
            classeIds: $user->isLearnerAudience() ? $classeIds : null,
            enseignantId: $user->isTeacher() ? $user->id : null,
            institutionId: $institution,
        );
        $enseignants = $this->enseignants($matiere, $classeIds, $institution);

        return [
            'matiere' => [
                'id' => $matiere->id,
                'nom' => $matiere->libelle,
                'code' => $matiere->code,
                'coefficient' => $matiere->coefficient,
            ],
            'combinaisons' => [],
            'enseignants' => $enseignants,
            'lessons' => $lessons,
            'seances_programmees' => [],
            'evaluations_programmees' => [],
            'statistiques' => $this->statistiques(count($lessons), count($enseignants), $matiere->coefficient),
            'matiere_id_local' => $matiere->id,
            'classes_concernees' => array_map(static fn (Classe $classe): array => [
                'id' => $classe->id,
                'nom' => $classe->libelle,
            ], $classes),
        ];
    }

    /**
     * Les classes de cette matière que l'appelant atteint, ou `null` s'il
     * n'atteint pas la matière. La gestion l'atteint même sans classe : une
     * matière au catalogue, pas encore au programme, reste à elle.
     *
     * @return list<Classe>|null
     */
    private function classesVisibles(Matiere $matiere, User $user, int $institution): ?array
    {
        $designe = $user->isTeacher() ? $user->id : null;

        $classes = Classe::query()
            ->where('institution_id', $institution)
            ->whereHas('matieres', static function ($programme) use ($matiere, $designe): void {
                $programme->where('matieres.id', $matiere->id);

                if ($designe !== null) {
                    $programme->where('classe_matiere.enseignant_id', $designe);
                }
            })
            ->orderBy('libelle')
            ->orderBy('id');

        if (! $user->isManager() && ! $user->isTeacher()) {
            // Portée d'apprenant, celle de tout rôle non reconnu (#941) : une
            // inscription ACTIVE, et rien d'autre (D57).
            $classes->whereHas('etudiants', static function ($inscrits) use ($user): void {
                $inscrits->where('users.id', $user->id)->where('classe_etudiant.statut', 'actif');
            });
        }

        $visibles = array_values($classes->get()->all());

        return $visibles === [] && ! $user->isManager() ? null : $visibles;
    }

    /**
     * Les formateurs désignés pour cette matière, dans les classes que
     * l'appelant atteint — pas au-delà.
     *
     * @param  list<int>  $classeIds
     * @return list<array{id: int, nom: string}>
     */
    private function enseignants(Matiere $matiere, array $classeIds, int $institution): array
    {
        $designes = DB::table('classe_matiere')
            ->where('matiere_id', $matiere->id)
            ->whereIn('classe_id', $classeIds)
            ->whereNotNull('enseignant_id')
            ->select('enseignant_id');

        $formateurs = User::query()
            ->where('institution_id', $institution)
            ->whereIn('id', $designes)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->all();

        return array_values(array_map(static fn (User $enseignant): array => [
            'id' => $enseignant->id,
            'nom' => $enseignant->name,
        ], $formateurs));
    }

    /**
     * Les clés de `MatiereLessonsAndStatsBuilder::computeStats`, pour que le
     * front lise la même forme dans les deux volets.
     *
     * @return array<string, int|null>
     */
    private function statistiques(int $lessons, int $enseignants, int $coefficient): array
    {
        return [
            'nombre_seances_programmees' => null,
            'nombre_seances_realisees' => null,
            'taux_realisation' => null,
            'nombre_evaluations' => 0,
            'nombre_enseignants' => $enseignants,
            'nombre_combinaisons' => 0,
            'nombre_lessons' => $lessons,
            'volume_horaire_total' => null,
            'coefficient' => $coefficient,
        ];
    }
}
