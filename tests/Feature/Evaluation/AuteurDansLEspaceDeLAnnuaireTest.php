<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use App\Services\Evaluation\EnseignantsDesEvaluations;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1537 : l'auteur d'une évaluation LOCALE (sans `klassci_enseignant_id`) est rendu dans l'espace
 * de l'annuaire des enseignants de son école, comme le compte connecté (#1536).
 *
 * Avant : `enseignant.id` était l'id LOCAL de l'auteur, quel que soit le mode. En école KLASSCI,
 * l'annuaire parle l'espace des enseignants KLASSCI : ce nombre pouvait désigner un AUTRE
 * enseignant. Le cas naît d'une école autonome passée en KLASSCI (`changeMode`), qui garde ses
 * évaluations locales.
 *
 * Chaque valeur est comparée à l'`id` que l'annuaire réel émet pour le même compte. La spec ne
 * permet pas un `enseignant.id` nul : hors annuaire, l'objet est nul et le nom reste dans
 * `enseignant_nom`.
 */
final class AuteurDansLEspaceDeLAnnuaireTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        // L'annuaire KLASSCI ne rend personne : il se replie sur la base locale
        // (`LocalTeacherDirectory`), qui émet `klassci_enseignant_id`.
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getEnseignants')->andReturn(['success' => true, 'data' => []]);
        });
    }

    public function test_ecole_klassci_l_auteur_avec_identite_est_rendu_dans_l_espace_de_l_annuaire(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $auteur = $this->membre($ecole, 'enseignant');
        $auteur->forceFill(['klassci_enseignant_id' => 42])->save();

        $presente = $this->presente($ecole, $this->evaluationLocale($ecole, $auteur));

        self::assertNotNull($presente['enseignant']);
        self::assertSame($this->idDansLAnnuaireServi($auteur), $presente['enseignant']['id']);
        self::assertSame(42, $presente['enseignant']['id']);
    }

    public function test_ecole_klassci_l_auteur_sans_identite_n_a_pas_d_id_mais_garde_son_nom(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $auteur = $this->membre($ecole, 'enseignant');

        $presente = $this->presente($ecole, $this->evaluationLocale($ecole, $auteur));

        self::assertNull($presente['enseignant'], 'Aucun id d\'un autre espace ne circule.');
        self::assertSame($auteur->name, $presente['enseignant_nom']);
        self::assertNull($this->idDansLAnnuaireServi($auteur));
    }

    /** Verrou : en école autonome, l'auteur enseignant garde son id local, celui de l'annuaire. */
    public function test_ecole_autonome_l_auteur_enseignant_est_rendu_par_son_id_local(): void
    {
        $ecole = $this->ecole();
        $auteur = $this->membre($ecole, 'enseignant');

        $presente = $this->presente($ecole, $this->evaluationLocale($ecole, $auteur));

        self::assertNotNull($presente['enseignant']);
        self::assertSame($auteur->id, $presente['enseignant']['id']);
        self::assertSame($this->idDansLAnnuaireServi($auteur), $presente['enseignant']['id']);
    }

    /** Changement assumé : un auteur qui n'est pas enseignant n'a pas d'id dans l'annuaire. */
    public function test_un_auteur_non_enseignant_n_a_pas_d_id_mais_garde_son_nom(): void
    {
        $ecole = $this->ecole();
        $auteur = $this->membre($ecole, 'admin');

        $presente = $this->presente($ecole, $this->evaluationLocale($ecole, $auteur));

        self::assertNull($presente['enseignant']);
        self::assertSame($auteur->name, $presente['enseignant_nom']);
    }

    private function evaluationLocale(Institution $ecole, User $auteur): Evaluation
    {
        return Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'enseignant_id' => $auteur->getKey(),
            'klassci_enseignant_id' => null,
        ]);
    }

    /** @return array{enseignant: array<string, mixed>|null, enseignant_nom: string|null} */
    private function presente(Institution $ecole, Evaluation $evaluation): array
    {
        app(TenantManager::class)->set($ecole);

        return app(EnseignantsDesEvaluations::class)->de(collect([$evaluation]))($evaluation);
    }

    /** L'`id` de la ligne que l'annuaire sert à ce compte (un enseignant ne voit que la sienne), ou null. */
    private function idDansLAnnuaireServi(User $compte): ?int
    {
        $lignes = array_values(array_filter(
            (array) $this->asTenant($compte)->getJson('/api/proxy/enseignants')->assertOk()->json('data'),
            static fn (mixed $ligne): bool => is_array($ligne) && ($ligne['email'] ?? null) === $compte->email,
        ));

        return isset($lignes[0]['id']) && is_int($lignes[0]['id']) ? $lignes[0]['id'] : null;
    }
}
