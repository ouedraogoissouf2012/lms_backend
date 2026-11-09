<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Catalogue\LocalClasseMatiereLinker;
use App\Services\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Une école indépendante, montée comme le produit la monte (ADR-EI-01).
 *
 * Les comptes sont LOCAUX : la fabrique `User` pose par défaut un jeton et des
 * identités KLASSCI, qu'aucun compte d'école indépendante n'a. Avec eux, un
 * refus obtenu par la source KLASSCI passait pour celui de la source locale —
 * constaté au lot 1, où un test « 404 local » était vert sur le code d'origine.
 */
trait BuildsEcoleIndependante
{
    protected function ecole(InstitutionMode $mode = InstitutionMode::Standalone): Institution
    {
        return Institution::factory()->create(['mode' => $mode]);
    }

    protected function membre(Institution $ecole, string $role): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'role' => $role,
            'klassci_token' => null,
            'klassci_id' => null,
            'klassci_enseignant_id' => null,
        ]);
    }

    protected function classe(Institution $ecole, string $libelle): Classe
    {
        return Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null, 'libelle' => $libelle]);
    }

    protected function matiere(Institution $ecole, string $libelle): Matiere
    {
        return Matiere::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_id' => null,
            'libelle' => $libelle,
            'coefficient' => 2,
        ]);
    }

    /**
     * Par le service du produit, pas par une insertion : c'est lui qui écrit
     * `classe_matiere` depuis l'écran de gestion. Il borne au tenant ambiant,
     * qu'on pose le temps de l'écriture.
     */
    protected function programme(Classe $classe, Matiere $matiere, ?User $enseignant): void
    {
        $tenants = app(TenantManager::class);
        $tenants->set(Institution::query()->findOrFail($classe->institution_id));
        app(LocalClasseMatiereLinker::class)->rattacher(
            (int) $classe->getKey(),
            (int) $matiere->getKey(),
            $enseignant === null ? null : (int) $enseignant->getKey(),
        );
        $tenants->reset();
    }

    protected function lecon(Classe $classe, Matiere $matiere, User $auteur, string $etat): Lesson
    {
        return Lesson::factory()->{$etat}()->create([
            'institution_id' => $classe->institution_id,
            'classe_id' => $classe->getKey(),
            'matiere_id' => $matiere->getKey(),
            'enseignant_id' => $auteur->getKey(),
        ]);
    }

    protected function inscrire(User $eleve, Classe $classe, string $statut): void
    {
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->getKey(),
            'user_id' => $eleve->getKey(),
            'statut' => $statut,
            'date_inscription' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
