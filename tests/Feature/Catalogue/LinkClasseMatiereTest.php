<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Catalogue\LocalClasseMatiereLinker;
use App\Services\Enrollment\LocalEnrollmentSource;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #848, troisième lot — composer le programme d'une classe.
 *
 * ## Ce qui se joue ici
 *
 * C'est le seul des trois lots qui relie DEUX entités : la porte est donc
 * ouverte à une fuite entre établissements que les deux précédents n'avaient
 * pas. Rattacher la matière d'une école voisine produirait une ligne d'apparence
 * normale, invisible à la relecture.
 *
 * SEPT des dix cas sont des refus. Et le dernier test prouve le PARCOURS : le
 * formateur désigné voit réellement la classe, via `LocalEnrollmentSource` —
 * autrement l'écriture serait correcte et inutile.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class LinkClasseMatiereTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_matiere_se_rattache_a_une_classe(): void
    {
        [$classe, $matiere] = $this->catalogueLocal();

        $this->rattacher($classe->getKey(), $matiere->getKey());

        self::assertSame(1, DB::table('classe_matiere')
            ->where('classe_id', $classe->getKey())
            ->where('matiere_id', $matiere->getKey())
            ->count());
    }

    public function test_le_formateur_designe_voit_la_classe(): void
    {
        // La preuve du PARCOURS : sans elle, l'écriture serait juste et inutile.
        [$classe, $matiere, $institution] = $this->catalogueLocal();
        $formateur = $this->formateur($institution);

        $this->rattacher($classe->getKey(), $matiere->getKey(), $formateur->getKey());

        self::assertSame(
            [$classe->getKey()],
            app(LocalEnrollmentSource::class)->classeIdsForTeacher($formateur)
        );
    }

    public function test_rejouer_le_rattachement_reaffecte_au_lieu_d_echouer(): void
    {
        // `classe_matiere` porte un unique (classe_id, matiere_id). Réaffecter
        // un cours est courant : ce doit être une mise à jour, pas une erreur.
        [$classe, $matiere, $institution] = $this->catalogueLocal();
        $premier = $this->formateur($institution);
        $second = $this->formateur($institution);

        $this->rattacher($classe->getKey(), $matiere->getKey(), $premier->getKey());
        $this->rattacher($classe->getKey(), $matiere->getKey(), $second->getKey());

        self::assertSame(1, DB::table('classe_matiere')->count());
        self::assertSame([], app(LocalEnrollmentSource::class)->classeIdsForTeacher($premier));
        self::assertSame([$classe->getKey()], app(LocalEnrollmentSource::class)->classeIdsForTeacher($second));
    }

    public function test_le_formateur_est_facultatif(): void
    {
        // On compose souvent un programme avant de savoir qui l'assurera.
        [$classe, $matiere] = $this->catalogueLocal();

        $this->rattacher($classe->getKey(), $matiere->getKey());

        self::assertNull(DB::table('classe_matiere')->value('enseignant_id'));
    }

    // ───────────────────────────────── les refus

    public function test_la_matiere_d_une_AUTRE_ecole_est_refusee(): void
    {
        // La fuite que ce lot rend possible : la ligne écrite paraîtrait normale.
        [$classe] = $this->catalogueLocal();
        $ailleurs = $this->catalogueLocal();

        try {
            $this->rattacher($classe->getKey(), $ailleurs[1]->getKey());
            self::fail('La matière d\'un autre établissement a été rattachée.');
        } catch (BusinessException) {
            self::assertSame(0, DB::table('classe_matiere')->count());
        }
    }

    public function test_la_classe_d_une_AUTRE_ecole_est_refusee(): void
    {
        $ailleurs = $this->catalogueLocal();
        [, $matiere] = $this->catalogueLocal();

        $this->expectException(BusinessException::class);

        $this->rattacher($ailleurs[0]->getKey(), $matiere->getKey());
    }

    public function test_un_formateur_d_une_AUTRE_ecole_est_refuse(): void
    {
        [$classe, $matiere] = $this->catalogueLocal();
        $etranger = $this->formateur($this->catalogueLocal()[2]);

        try {
            $this->rattacher($classe->getKey(), $matiere->getKey(), $etranger->getKey());
            self::fail('Un formateur d\'un autre établissement a été désigné.');
        } catch (BusinessException) {
            self::assertSame(0, DB::table('classe_matiere')->count());
        }
    }

    public function test_un_etudiant_ne_peut_pas_etre_designe_formateur(): void
    {
        // `classe_matiere.enseignant_id` ne désigne qu'UNE personne par couple :
        // y laisser entrer un étudiant lui ouvrirait la classe.
        [$classe, $matiere, $institution] = $this->catalogueLocal();
        $etudiant = User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'etudiant',
        ]);

        $this->expectException(BusinessException::class);

        $this->rattacher($classe->getKey(), $matiere->getKey(), $etudiant->getKey());
    }

    public function test_un_etablissement_klassci_ne_compose_pas_son_programme(): void
    {
        [$classe, $matiere] = $this->catalogueLocal(InstitutionMode::Klassci);

        $this->expectException(BusinessException::class);

        $this->rattacher($classe->getKey(), $matiere->getKey());
    }

    public function test_sans_tenant_resolu_rien_n_est_ecrit(): void
    {
        [$classe, $matiere] = $this->catalogueLocal();
        app(TenantManager::class)->reset();

        try {
            $this->rattacher($classe->getKey(), $matiere->getKey());
            self::fail('Un rattachement a eu lieu sans établissement résolu.');
        } catch (BusinessException) {
            self::assertSame(0, DB::table('classe_matiere')->count());
        }
    }

    // ───────────────────────────────── harnais

    /**
     * @return array{0: Classe, 1: Matiere, 2: Institution}
     */
    private function catalogueLocal(InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $institution = Institution::factory()->create(['mode' => $mode]);
        app(TenantManager::class)->set($institution);

        return [
            Classe::factory()->create(['institution_id' => $institution->getKey(), 'klassci_id' => null]),
            Matiere::factory()->create(['institution_id' => $institution->getKey(), 'klassci_id' => null]),
            $institution,
        ];
    }

    private function formateur(Institution $institution): User
    {
        return User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'enseignant',
        ]);
    }

    private function rattacher(int $classeId, int $matiereId, ?int $enseignantId = null): void
    {
        app(LocalClasseMatiereLinker::class)->rattacher($classeId, $matiereId, $enseignantId);
    }
}
