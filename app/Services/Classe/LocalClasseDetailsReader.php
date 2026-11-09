<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * La fiche d'une classe et son roster, lus dans les tables LOCALES, pour une
 * école créée depuis le LMS.
 *
 * ## Une seule classe pour trois lectures
 *
 * La porte historique, la porte locale et le roster appliquent la MÊME grille
 * d'accès et le MÊME roster actif. Trois classes la recopieraient trois fois,
 * et trois copies finissent par diverger. Dans une école à source locale, les
 * deux portes attendent le même espace, l'id local (ADR-760-01, amendement
 * EI-02).
 *
 * ## Qui atteint la classe
 *
 * | Appelant | Classe | Roster |
 * |---|---|---|
 * | gestion (coordinateur, admin) | toute classe de l'établissement | les actifs |
 * | enseignant | une classe où il est désigné formateur | les actifs |
 * | apprenant, et tout rôle non reconnu (#941) | une classe où son inscription est ACTIVE (D57) | `[]` (GHSA-gg7j) |
 *
 * Hors de ces cas, 404 « Classe non trouvée » — le corps de la source KLASSCI,
 * qui ne dit pas si la classe existe ailleurs.
 *
 * ## Ce qu'on ne sait pas vaut `null`, pas 0
 *
 * Capacité, séances de la semaine, taux de remplissage : aucune table locale
 * ne les porte. Même règle que `ClasseDetailsQueryService::computeStats`,
 * « bloc non livré, null, jamais 0 ». Les évaluations sont vides : provisoire,
 * jusqu'à la fonction qui les construit (D72).
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
final class LocalClasseDetailsReader implements ClasseDetailsReader, ClasseEtudiantsReader, ClasseLocalDoorReader
{
    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function getDetailsForUser(int $classeId, User $user): array
    {
        return $this->fiche($classeId, $user);
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function detailsForLocalId(int $classeId, User $user): array
    {
        return $this->fiche($classeId, $user);
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function getEtudiants(int $classeId, User $user): array
    {
        $classe = $this->classeAtteinte($classeId, $user);

        if ($classe === null) {
            return $this->introuvable();
        }

        $etudiants = $user->isStaff() ? $this->rosterActif($classe) : [];

        return ['status' => 200, 'payload' => [
            'success' => true,
            'data' => ['etudiants' => $etudiants, 'total' => count($etudiants)],
        ]];
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function fiche(int $classeId, User $user): array
    {
        $classe = $this->classeAtteinte($classeId, $user);

        if ($classe === null) {
            return $this->introuvable();
        }

        $actifs = $this->rosterActif($classe);
        $programme = $this->programme($classe);

        return ['status' => 200, 'payload' => [
            'success' => true,
            'data' => [
                'classe' => [
                    'id' => $classe->id,
                    'name' => $classe->libelle,
                    'code' => $classe->code,
                    'filiere' => null,
                    'niveau' => null,
                ],
                // L'effectif reste visible de tous ; la LISTE, du personnel
                // seulement (GHSA-gg7j).
                'etudiants' => $user->isStaff() ? $actifs : [],
                'matieres_disponibles' => $programme,
                'emploi_temps_semaine' => [],
                'evaluations_programmees' => [],
                'statistiques' => [
                    'nombre_etudiants' => count($actifs),
                    'nombre_seances_semaine' => null,
                    'nombre_evaluations_programmees' => 0,
                    'nombre_matieres' => count($programme),
                    'capacite_classe' => null,
                    'taux_remplissage' => null,
                ],
            ],
        ]];
    }

    /**
     * La classe, si l'appelant l'atteint — sinon `null`, rendu en 404.
     */
    private function classeAtteinte(int $classeId, User $user): ?Classe
    {
        if ($user->institution_id === null) {
            return null;
        }

        // Bornage EXPLICITE : le scope global est fail-open sans tenant.
        $classe = Classe::query()
            ->where('institution_id', $user->institution_id)
            ->whereKey($classeId);

        if ($user->isManager()) {
            return $classe->first();
        }

        if ($user->isTeacher()) {
            $classe->whereHas('matieres', static function ($programme) use ($user): void {
                $programme->where('classe_matiere.enseignant_id', $user->id);
            });
        } else {
            $classe->whereHas('etudiants', static function ($inscrits) use ($user): void {
                $inscrits->where('users.id', $user->id)->where('classe_etudiant.statut', 'actif');
            });
        }

        return $classe->first();
    }

    /**
     * Les inscriptions ACTIVES, bornées à l'établissement de la classe.
     *
     * @return list<array{id: int, name: string, email: string|null, matricule: null, statut: string}>
     */
    private function rosterActif(Classe $classe): array
    {
        $inscrits = User::query()
            ->where('institution_id', $classe->institution_id)
            ->whereIn('id', DB::table('classe_etudiant')
                ->where('classe_id', $classe->id)
                ->where('statut', 'actif')
                ->select('user_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        return array_values($inscrits->map(static fn (User $etudiant): array => [
            'id' => $etudiant->id,
            'name' => $etudiant->name,
            'email' => $etudiant->email,
            'matricule' => null,
            'statut' => 'actif',
        ])->all());
    }

    /**
     * Le programme de la classe : ses matières, et le formateur désigné pour
     * chacune (0 ou 1 — `classe_matiere` en porte un au plus).
     *
     * @return list<array{id: int, nom: string, code: string|null, coefficient: int, enseignants: list<array{id: int, nom: string}>}>
     */
    private function programme(Classe $classe): array
    {
        /** @var array<int|string, mixed> $designations matiere_id => enseignant_id */
        $designations = DB::table('classe_matiere')
            ->where('classe_id', $classe->id)
            ->pluck('enseignant_id', 'matiere_id')
            ->all();

        $matieres = Matiere::query()
            ->where('institution_id', $classe->institution_id)
            ->whereIn('id', array_keys($designations))
            ->orderBy('libelle')
            ->orderBy('id')
            ->get();

        $formateurs = User::query()
            ->where('institution_id', $classe->institution_id)
            ->whereIn('id', array_values(array_filter($designations, 'is_numeric')))
            ->get(['id', 'name'])
            ->keyBy('id');

        return array_values($matieres->map(static function (Matiere $matiere) use ($designations, $formateurs): array {
            $designe = $designations[$matiere->id] ?? null;
            $formateur = is_numeric($designe) ? $formateurs->get((int) $designe) : null;

            return [
                'id' => $matiere->id,
                'nom' => $matiere->libelle,
                'code' => $matiere->code,
                'coefficient' => $matiere->coefficient,
                'enseignants' => $formateur instanceof User ? [['id' => $formateur->id, 'nom' => $formateur->name]] : [],
            ];
        })->all());
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function introuvable(): array
    {
        return ['status' => 404, 'payload' => ['success' => false, 'message' => 'Classe non trouvée']];
    }
}
