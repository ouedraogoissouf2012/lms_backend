<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Exceptions\BusinessException;
use App\Models\Institution;
use App\Models\Matiere;
use App\Services\Catalogue\LocalMatiereCreator;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #797 / #848 — une école autonome a enfin quelque chose à enseigner.
 *
 * Le seul créateur d'une `Matiere` du dépôt était `ClasseMatieresSynchronizer` —
 * la synchronisation KLASSCI. Une école autonome avait des classes depuis #860,
 * mais aucune matière : un formateur n'avait rien à y mettre.
 *
 * Trois des cinq cas sont des REFUS : ouvrir un chemin d'écriture dans un
 * produit multi-établissements est l'occasion classique de trop ouvrir.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class LocalMatiereCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_ecole_autonome_cree_sa_matiere(): void
    {
        $institution = $this->pourTenant(InstitutionMode::Standalone);

        $matiere = $this->creer(['libelle' => 'Traitement de texte', 'code' => 'BUR-TXT']);

        self::assertSame('Traitement de texte', $matiere->libelle);
        self::assertSame($institution->getKey(), $matiere->institution_id);
        self::assertNull(
            $matiere->klassci_id,
            'Inventer un identifiant KLASSCI la ferait entrer dans les correspondances '
            .'par whereIn() où elle n\'a rien à faire.'
        );
    }

    public function test_plusieurs_matieres_locales_cohabitent(): void
    {
        // L'unique porte sur (klassci_id, institution_id). Toutes les matières
        // locales y valent NULL — SQL autorise les NULL en doublon, sans quoi la
        // DEUXIÈME matière de l'établissement échouerait.
        $this->pourTenant(InstitutionMode::Standalone);

        $this->creer(['libelle' => 'Traitement de texte']);
        $this->creer(['libelle' => 'Tableur']);
        $this->creer(['libelle' => 'Présentation']);

        self::assertSame(3, Matiere::query()->count());
    }

    public function test_coefficient_et_credit_valent_un_par_defaut(): void
    {
        // Une matière se crée souvent avant d'être pondérée : ne pas l'exiger
        // évite d'imposer à l'écran une décision que le métier n'a pas prise.
        $this->pourTenant(InstitutionMode::Standalone);

        $matiere = $this->creer(['libelle' => 'Tableur']);

        self::assertSame(1, (int) $matiere->coefficient);
        self::assertSame(1, (int) $matiere->credit);
    }

    public function test_un_etablissement_klassci_ne_cree_pas_de_matiere(): void
    {
        $this->pourTenant(InstitutionMode::Klassci);

        $this->expectException(BusinessException::class);

        $this->creer(['libelle' => 'Matière que KLASSCI devrait fournir']);
    }

    public function test_sans_tenant_resolu_aucune_matiere_n_est_ecrite(): void
    {
        app(TenantManager::class)->reset();

        try {
            $this->creer(['libelle' => 'Matière hors établissement']);
            self::fail('Une matière a été créée sans établissement résolu.');
        } catch (BusinessException) {
            self::assertSame(0, Matiere::query()->count());
        }
    }

    public function test_deux_ecoles_nomment_la_meme_matiere_sans_se_gener(): void
    {
        $this->pourTenant(InstitutionMode::Standalone);
        $this->creer(['libelle' => 'Tableur', 'code' => 'BUR-TAB']);

        $voisine = $this->pourTenant(InstitutionMode::Standalone);
        $chezElle = $this->creer(['libelle' => 'Tableur', 'code' => 'BUR-TAB']);

        self::assertSame($voisine->getKey(), $chezElle->institution_id);
        self::assertSame(2, Matiere::query()->withoutGlobalScopes()->count());
    }

    private function pourTenant(InstitutionMode $mode): Institution
    {
        $institution = Institution::factory()->create(['mode' => $mode]);
        app(TenantManager::class)->set($institution);

        return $institution;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function creer(array $donnees): Matiere
    {
        /** @var array{libelle: string, code?: string|null, description?: string|null, coefficient?: int|null, credit?: int|null} $donnees */
        return app(LocalMatiereCreator::class)->creer($donnees);
    }
}
