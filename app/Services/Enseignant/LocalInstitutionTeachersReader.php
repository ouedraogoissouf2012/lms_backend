<?php

declare(strict_types=1);

namespace App\Services\Enseignant;

use App\Models\User;
use App\Services\Klassci\TeacherDirectory\TeacherDirectoryVisibility;
use Illuminate\Support\Facades\DB;

/**
 * Les enseignants d'une école créée depuis le LMS, ou basculée (ADR-EI-01,
 * lot 3b). Aucun appel à KLASSCI, aucune écriture de cache.
 *
 * - **Qui est lu** : la règle unique `TeacherDirectoryVisibility::restrictLocal`
 *   (D120), bornée explicitement à l'établissement. La gestion lit tous les
 *   enseignants, un enseignant sa ligne, tout autre rôle aucune.
 * - **Les ids sont LOCAUX** : `users.id`, jamais un id KLASSCI, et aucune clé
 *   `klassci_*` (D132 Q3). Un compte d'une école basculée garde son
 *   `klassci_id`, qui n'est pas rendu.
 * - **Les détails** : les matières où l'enseignant est désigné, avec leurs
 *   classes, et ses classes. C'est la règle de `ClassesEnseignees` (désignation
 *   sur `classe_matiere`, matière et classe de l'établissement), lue en UNE
 *   requête pour toute la liste. Ce que le LMS ne tient pas (heures, taux,
 *   séances, filière, niveau) vaut `null`, et aucun bloc `statistiques` n'est
 *   rendu (D120) : le front masque alors ces blocs.
 */
final class LocalInstitutionTeachersReader implements InstitutionTeachersReader
{
    private const SOURCE = 'lms_local';

    /** Les rôles d'un enseignant, ceux du repli local d'avant le lot. */
    private const ROLES = ['enseignant', 'teacher'];

    public function __construct(
        private readonly TeacherDirectoryVisibility $visibility,
    ) {}

    public function annuaire(User $lecteur): array
    {
        return $this->reponse($this->lignes($lecteur, false));
    }

    public function listeEnrichie(User $lecteur, bool $details): array
    {
        return $this->reponse($this->lignes($lecteur, $details));
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return array{success: true, data: list<array<string, mixed>>, meta: array{total: int, source: string}}
     */
    private function reponse(array $lignes): array
    {
        return ['success' => true, 'data' => $lignes, 'meta' => ['total' => count($lignes), 'source' => self::SOURCE]];
    }

    /** @return list<array<string, mixed>> */
    private function lignes(User $lecteur, bool $details): array
    {
        $enseignants = $this->visibility
            ->restrictLocal($lecteur, User::query()->whereIn('role', self::ROLES))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $parEnseignant = $details && $enseignants->isNotEmpty() && is_int($lecteur->institution_id)
            ? $this->designationsParEnseignant($enseignants->modelKeys(), $lecteur->institution_id)
            : [];

        return array_values($enseignants->map(fn (User $enseignant): array => $details
            ? $this->ligne($enseignant) + $this->details($parEnseignant[$enseignant->id] ?? [])
            : $this->ligne($enseignant))->all());
    }

    /** @return array<string, mixed> */
    private function ligne(User $enseignant): array
    {
        return [
            'id' => $enseignant->id,
            'nom' => $enseignant->name,
            'name' => $enseignant->name,
            'prenom' => '',
            'email' => $enseignant->email,
            'telephone' => $enseignant->phone,
            // Aucune colonne locale ne les porte.
            'matricule' => null,
            'specialization' => null,
            'status' => null,
        ];
    }

    /**
     * Les désignations des enseignants lus, bornées à l'établissement par la
     * classe ET par la matière, triées par matière puis par classe, rangées
     * par enseignant.
     *
     * @param  array<int, int|string>  $enseignantIds
     * @return array<int, list<array{matiere_id: int, matiere_nom: ?string, matiere_code: ?string, classe_id: int, classe_nom: ?string}>>
     */
    private function designationsParEnseignant(array $enseignantIds, int $institution): array
    {
        $lignes = DB::table('classe_matiere')
            ->join('classes', 'classes.id', '=', 'classe_matiere.classe_id')
            ->join('matieres', 'matieres.id', '=', 'classe_matiere.matiere_id')
            ->where('classes.institution_id', $institution)
            ->where('matieres.institution_id', $institution)
            ->whereIn('classe_matiere.enseignant_id', $enseignantIds)
            ->orderBy('matieres.libelle')->orderBy('matieres.id')
            ->orderBy('classes.libelle')->orderBy('classes.id')
            ->get([
                'classe_matiere.enseignant_id',
                'matieres.id as matiere_id', 'matieres.libelle as matiere_nom', 'matieres.code as matiere_code',
                'classes.id as classe_id', 'classes.libelle as classe_nom',
            ]);

        $parEnseignant = [];
        foreach ($lignes as $ligne) {
            $enseignant = self::entier($ligne->enseignant_id ?? null);
            $matiere = self::entier($ligne->matiere_id ?? null);
            $classe = self::entier($ligne->classe_id ?? null);
            if ($enseignant === null || $matiere === null || $classe === null) {
                continue;
            }
            $parEnseignant[$enseignant][] = [
                'matiere_id' => $matiere,
                'matiere_nom' => self::texte($ligne->matiere_nom ?? null),
                'matiere_code' => self::texte($ligne->matiere_code ?? null),
                'classe_id' => $classe,
                'classe_nom' => self::texte($ligne->classe_nom ?? null),
            ];
        }

        return $parEnseignant;
    }

    /**
     * @param  list<array{matiere_id: int, matiere_nom: ?string, matiere_code: ?string, classe_id: int, classe_nom: ?string}>  $designations  celles d'UN enseignant
     * @return array{matieres: list<array<string, mixed>>, classes: list<array<string, mixed>>}
     */
    private function details(array $designations): array
    {
        $matieres = [];
        $classes = [];
        foreach ($designations as $d) {
            $matieres[$d['matiere_id']] ??= [
                'id' => $d['matiere_id'],
                'nom' => $d['matiere_nom'],
                'code' => $d['matiere_code'],
                'classes' => [],
                // Aucune donnée locale : le front masque ces statistiques.
                'heures_prevues' => null,
                'heures_effectuees' => null,
                'taux_realisation' => null,
                'nb_seances_total' => null,
                'nb_seances_effectuees' => null,
            ];
            $matieres[$d['matiere_id']]['classes'][] = ['id' => $d['classe_id'], 'nom' => $d['classe_nom']];
            $classes[$d['classe_id']] ??= ['id' => $d['classe_id'], 'nom' => $d['classe_nom'], 'filiere' => null, 'niveau' => null];
        }

        // Les classes de l'enseignant, triées comme celles d'une matière.
        usort($classes, static fn (array $a, array $b): int => [$a['nom'], $a['id']] <=> [$b['nom'], $b['id']]);

        return ['matieres' => array_values($matieres), 'classes' => $classes];
    }

    private static function entier(mixed $valeur): ?int
    {
        if (is_int($valeur)) {
            return $valeur;
        }

        return is_string($valeur) && ctype_digit($valeur) ? (int) $valeur : null;
    }

    private static function texte(mixed $valeur): ?string
    {
        return is_string($valeur) ? $valeur : null;
    }
}
