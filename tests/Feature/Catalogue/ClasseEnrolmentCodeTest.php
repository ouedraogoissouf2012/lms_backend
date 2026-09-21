<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Enrollment\ClasseEnrolmentCode;
use App\Services\Enrollment\ClasseEnrolmentCodeService;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #846 (lot B) — le code court qu'on dicte pour rejoindre une classe.
 *
 * ADR-803-03 : « court, à casse insensible, sans les glyphes qu'on confond en le
 * dictant ; révocable et régénérable **sans toucher aux inscriptions déjà
 * faites** ». Cette dernière propriété est la plus facile à casser en
 * régénérant — le test qui la garde est ici, en majuscules.
 *
 * @see docs/adr/2026-09-15-803-03-trois-portes-un-service.md
 */
final class ClasseEnrolmentCodeTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────── la forme du code

    public function test_le_code_exclut_les_glyphes_qu_on_confond(): void
    {
        // `0`/`O` et `1`/`I`/`L` : l'ADR les nomme, parce que ce code circule
        // par téléphone et par WhatsApp, pas par copier-coller.
        for ($i = 0; $i < 200; $i++) {
            $code = ClasseEnrolmentCode::tirer();

            self::assertSame(6, strlen($code));
            self::assertSame(
                0,
                preg_match('/[01ILO]/', $code),
                "Le code « {$code} » contient un glyphe ambigu."
            );
        }
    }

    public function test_la_normalisation_est_faite_en_php_et_non_par_le_moteur(): void
    {
        // Mesure du 21/09 : SQLite est SENSIBLE à la casse, MySQL ne l'est pas.
        // S'en remettre à la collation donnerait deux comportements.
        self::assertSame('BUR7K2', ClasseEnrolmentCode::normaliser('  bur7k2 '));
    }

    // ───────────────────────── ce qui doit s'ouvrir

    public function test_generer_pose_un_code_sur_la_classe(): void
    {
        $classe = $this->classeLocale();

        $code = $this->service()->generer($classe->getKey());

        self::assertSame($code, $classe->fresh()?->code_inscription);
        self::assertNull($classe->fresh()?->code_inscription_revoque_le);
    }

    public function test_REGENERER_NE_DESINSCRIT_PERSONNE(): void
    {
        // LA propriété de l'ADR. Régénérer ferme la porte aux suivants, il
        // n'expulse pas ceux qui sont entrés.
        $classe = $this->classeLocale();
        $eleve = User::factory()->create([
            'institution_id' => $classe->institution_id,
            'role' => 'etudiant',
        ]);
        $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);

        $premier = $this->service()->generer($classe->getKey());
        $second = $this->service()->generer($classe->getKey());

        self::assertNotSame($premier, $second);
        self::assertSame(
            1,
            DB::table('classe_etudiant')->where('classe_id', $classe->getKey())->count(),
            'Régénérer le code ne doit toucher AUCUNE inscription.'
        );
    }

    public function test_revoquer_ferme_la_porte_sans_effacer_la_valeur(): void
    {
        $classe = $this->classeLocale();
        $code = $this->service()->generer($classe->getKey());

        $this->service()->revoquer($classe->getKey());

        $relu = $classe->fresh();
        self::assertSame($code, $relu?->code_inscription, 'La valeur demeure : voir la migration.');
        self::assertNotNull($relu?->code_inscription_revoque_le);
    }

    public function test_regenerer_rouvre_une_classe_revoquee(): void
    {
        $classe = $this->classeLocale();
        $this->service()->generer($classe->getKey());
        $this->service()->revoquer($classe->getKey());

        $this->service()->generer($classe->getKey());

        self::assertNull($classe->fresh()?->code_inscription_revoque_le);
    }

    public function test_deux_ecoles_peuvent_tirer_le_meme_code(): void
    {
        // L'unique porte sur (institution_id, code_inscription) : un unique
        // GLOBAL ferait échouer la seconde école.
        $ici = $this->classeLocale();
        $ailleurs = $this->classeLocale();

        DB::table('classes')->where('id', $ici->getKey())->update(['code_inscription' => 'BUR7K2']);
        DB::table('classes')->where('id', $ailleurs->getKey())->update(['code_inscription' => 'BUR7K2']);

        self::assertSame(2, DB::table('classes')->where('code_inscription', 'BUR7K2')->count());
    }

    public function test_deux_classes_d_une_meme_ecole_ne_partagent_pas_un_code(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        app(TenantManager::class)->set($ecole);
        $une = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null]);
        $autre = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null]);

        DB::table('classes')->where('id', $une->getKey())->update(['code_inscription' => 'BUR7K2']);

        $this->expectException(QueryException::class);

        DB::table('classes')->where('id', $autre->getKey())->update(['code_inscription' => 'BUR7K2']);
    }

    public function test_plusieurs_classes_sans_code_cohabitent(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        app(TenantManager::class)->set($ecole);

        Classe::factory()->count(3)->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null]);

        self::assertSame(3, Classe::query()->whereNull('code_inscription')->count());
    }

    // ───────────────────────── les refus

    public function test_un_etablissement_klassci_ne_distribue_pas_de_code(): void
    {
        $classe = $this->classeLocale(InstitutionMode::Klassci);

        $this->expectException(BusinessException::class);

        $this->service()->generer($classe->getKey());
    }

    public function test_la_classe_d_une_AUTRE_ecole_est_refusee(): void
    {
        $ailleurs = $this->classeLocale();
        $this->classeLocale(); // le tenant courant devient la seconde école

        try {
            $this->service()->generer($ailleurs->getKey());
            self::fail('Le code d\'une classe voisine a été émis.');
        } catch (BusinessException) {
            self::assertNull($ailleurs->fresh()?->code_inscription);
        }
    }

    public function test_sans_tenant_resolu_aucun_code_n_est_emis(): void
    {
        $classe = $this->classeLocale();
        app(TenantManager::class)->reset();

        try {
            $this->service()->generer($classe->getKey());
            self::fail('Un code a été émis sans établissement résolu.');
        } catch (BusinessException) {
            self::assertNull($classe->fresh()?->code_inscription);
        }
    }

    public function test_revoquer_une_classe_sans_code_est_refuse(): void
    {
        $classe = $this->classeLocale();

        $this->expectException(BusinessException::class);

        $this->service()->revoquer($classe->getKey());
    }

    // ───────────────────────── harnais

    private function service(): ClasseEnrolmentCodeService
    {
        // Résolu à CHAQUE appel : le service reçoit l'autorité du tenant
        // COURANT. Le mémoïser figerait le premier établissement du test.
        return app(ClasseEnrolmentCodeService::class);
    }

    private function classeLocale(InstitutionMode $mode = InstitutionMode::Standalone): Classe
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);
        app(TenantManager::class)->set($ecole);

        return Classe::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_id' => null,
        ]);
    }
}
