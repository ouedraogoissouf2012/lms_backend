<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Institution;
use App\Services\Catalogue\LocalClasseCreator;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #848 — une école autonome crée enfin ses propres classes.
 *
 * ## Ce que ces tests gardent SURTOUT
 *
 * Ouvrir un chemin d'écriture dans un produit multi-établissements est
 * l'occasion classique de trop ouvrir. Quatre des six cas sont donc des REFUS :
 * l'établissement KLASSCI, l'absence de tenant, la collision de code, et la
 * fuite vers l'établissement voisin.
 *
 * Le refus KLASSCI n'est pas une préférence produit : deux sources écrivant la
 * même réalité est la cause racine de #673, déjà payée une fois.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 */
final class LocalClasseCreationTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────────── ce qui doit s'ouvrir

    public function test_une_ecole_autonome_cree_sa_classe(): void
    {
        $institution = $this->pourTenant(InstitutionMode::Standalone);

        $classe = $this->creer(['libelle' => 'Bureautique — groupe A', 'code' => 'BUR-A']);

        self::assertSame('Bureautique — groupe A', $classe->libelle);
        self::assertSame('BUR-A', $classe->code);
        self::assertSame($institution->getKey(), $classe->institution_id);
        self::assertNull(
            $classe->klassci_id,
            'Une classe locale sans identifiant KLASSCI : lui en inventer un la rendrait '
            .'indiscernable d\'une classe miroitée.'
        );
    }

    public function test_plusieurs_classes_sans_code_cohabitent(): void
    {
        // L'unique porte sur (institution_id, code). Sans normalisation du vide
        // en nul, la DEUXIÈME classe sans code entrerait en collision — c'est le
        // cas de TOUTES les classes venues de KLASSCI.
        $this->pourTenant(InstitutionMode::Standalone);

        $this->creer(['libelle' => 'Groupe du matin']);
        $this->creer(['libelle' => 'Groupe du soir', 'code' => '']);
        $this->creer(['libelle' => 'Groupe du samedi', 'code' => '   ']);

        self::assertSame(3, Classe::query()->count());
        self::assertSame(0, Classe::query()->whereNotNull('code')->count());
    }

    // ───────────────────────────────── ce qui doit être refusé

    public function test_un_etablissement_klassci_ne_cree_pas_de_classe(): void
    {
        $this->pourTenant(InstitutionMode::Klassci);

        $this->expectException(BusinessException::class);

        $this->creer(['libelle' => 'Classe que KLASSCI devrait fournir']);
    }

    public function test_sans_tenant_resolu_aucune_classe_n_est_ecrite(): void
    {
        // Fail-secure : un job, une commande, une route publique. Une classe sans
        // `institution_id` échapperait au scope multi-tenant.
        app(TenantManager::class)->reset();

        try {
            $this->creer(['libelle' => 'Classe hors établissement']);
            self::fail('Une classe a été créée sans établissement résolu.');
        } catch (BusinessException) {
            self::assertSame(0, Classe::query()->count());
        }
    }

    public function test_deux_classes_ne_partagent_pas_un_code(): void
    {
        // La base tranche, pas un SELECT préalable : deux requêtes concurrentes
        // passeraient toutes deux un contrôle applicatif.
        $this->pourTenant(InstitutionMode::Standalone);
        $this->creer(['libelle' => 'Groupe A', 'code' => 'BUR-A']);

        $this->expectException(QueryException::class);

        $this->creer(['libelle' => 'Un autre groupe A', 'code' => 'BUR-A']);
    }

    public function test_deux_ecoles_peuvent_employer_le_meme_code(): void
    {
        // Réciproque de l'unique : deux établissements sont indépendants et
        // peuvent légitimement nommer une classe « BUR-A ». Un unique GLOBAL
        // ferait échouer la seconde école.
        $this->pourTenant(InstitutionMode::Standalone);
        $this->creer(['libelle' => 'Groupe A', 'code' => 'BUR-A']);

        $voisine = $this->pourTenant(InstitutionMode::Standalone);
        $chezLaVoisine = $this->creer(['libelle' => 'Groupe A', 'code' => 'BUR-A']);

        self::assertSame($voisine->getKey(), $chezLaVoisine->institution_id);
        self::assertSame(2, Classe::query()->withoutGlobalScopes()->count());
    }

    // ───────────────────────────────── harnais

    private function pourTenant(InstitutionMode $mode): Institution
    {
        $institution = Institution::factory()->create(['mode' => $mode]);
        app(TenantManager::class)->set($institution);

        return $institution;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function creer(array $donnees): Classe
    {
        // Résolu À CHAQUE appel : le service reçoit l'autorité du tenant COURANT.
        // Le mémoïser figerait le premier établissement de la méthode de test.
        /** @var array{libelle: string, code?: string|null, description?: string|null, effectif?: int|null} $donnees */
        return app(LocalClasseCreator::class)->creer($donnees);
    }
}
