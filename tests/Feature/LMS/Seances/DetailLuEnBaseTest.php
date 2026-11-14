<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Seances\SeanceVisioEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * École KLASSCI : quand KLASSCI ne rend pas la séance demandée, le détail est lu en
 * base locale (`SeanceVisioEnricher::loadFromLocalDbFallback`). Ce repli suit la
 * séance de l'appelant : il la rend à qui la gère, et à un élève de sa classe (la
 * règle des élèves d'une séance, `ClasseAudienceSource::containsStudent`) ; sinon,
 * la réponse est celle d'une séance inexistante.
 *
 * KLASSCI ne rend rien dans ces tests (`Http::fake`) : chaque lecture passe par le
 * repli.
 */
final class DetailLuEnBaseTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const SEANCE = 900;

    private const KLASSCI_CLASSE = 77;

    private const ENSEIGNANT_KLASSCI = 42;

    private const NON_RETENUE = 'Repli local du detail : seance non retenue';

    private Institution $ecole;

    private Classe $classe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);
        $this->ecole = $this->ecole(InstitutionMode::Klassci);
        $this->classe = $this->classe($this->ecole, '6e A');
        $this->classe->forceFill(['klassci_id' => self::KLASSCI_CLASSE])->save();
    }

    public function test_un_eleve_d_une_autre_classe_recoit_la_reponse_d_une_seance_inexistante(): void
    {
        $this->seance();
        $eleve = $this->eleve();
        $this->inscrire($eleve, $this->classe($this->ecole, '5e B'), 'actif');

        $refus = $this->asTenant($eleve)->getJson($this->url(self::SEANCE))->assertNotFound()->json();
        $inexistante = $this->asTenant($eleve)->getJson($this->url(424242))->assertNotFound()->json();

        self::assertSame($inexistante, $refus);
    }

    public function test_un_eleve_de_la_classe_lit_le_detail(): void
    {
        $this->seance();
        $eleve = $this->eleve();
        $this->inscrire($eleve, $this->classe, 'actif');

        $this->asTenant($eleve)->getJson($this->url(self::SEANCE))->assertOk()
            ->assertJsonPath('data.seance.id', self::SEANCE);
    }

    public function test_un_eleve_connu_par_le_miroir_de_sa_classe_lit_le_detail(): void
    {
        $this->seance();
        $eleve = $this->eleve();
        UserClass::query()->withoutGlobalScopes()->create([
            'user_id' => $eleve->id, 'institution_id' => $this->ecole->id,
            'klassci_classe_id' => self::KLASSCI_CLASSE, 'classe_nom' => '6e A',
        ]);

        $this->asTenant($eleve)->getJson($this->url(self::SEANCE))->assertOk();
    }

    public function test_un_eleve_de_la_classe_ne_lit_pas_une_seance_archivee(): void
    {
        $this->seance(['is_active' => false]);
        $eleve = $this->eleve();
        $this->inscrire($eleve, $this->classe, 'actif');

        $this->asTenant($eleve)->getJson($this->url(self::SEANCE))->assertNotFound();
    }

    public function test_l_enseignant_de_la_seance_lit_le_detail(): void
    {
        $this->seance();

        $this->asTenant($this->enseignant(self::ENSEIGNANT_KLASSCI))->getJson($this->url(self::SEANCE))->assertOk();
    }

    public function test_un_autre_enseignant_recoit_la_reponse_d_une_seance_inexistante(): void
    {
        $this->seance();

        $this->asTenant($this->enseignant(43))->getJson($this->url(self::SEANCE))->assertNotFound();
    }

    public function test_un_coordinateur_lit_le_detail(): void
    {
        $this->seance();
        $coordinateur = $this->membre($this->ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton'])->save();

        $this->asTenant($coordinateur)->getJson($this->url(self::SEANCE))->assertOk();
    }

    /** Le journal compte la séance non retenue, sans identité d'élève. */
    public function test_le_journal_compte_sans_identite(): void
    {
        $journal = Mockery::spy(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $journal);
        $this->seance();
        $eleve = $this->eleve();

        $this->asTenant($eleve)->getJson($this->url(self::SEANCE))->assertNotFound();

        $journal->shouldHaveReceived('info')
            ->withArgs(static fn (string $message, array $contexte = []): bool => $message === self::NON_RETENUE
                && array_keys($contexte) === ['seance_id', 'role']
                && ! in_array($eleve->id, $contexte, true))
            ->once();
    }

    /** Sans tenant, la ligne est cherchée dans l'établissement du compte, jamais dans un autre. */
    public function test_sans_tenant_la_ligne_est_celle_de_l_etablissement_du_compte(): void
    {
        $ailleurs = $this->ecole(InstitutionMode::Klassci);
        Seance::factory()->for($ailleurs)->create(['klassci_seance_id' => self::SEANCE, 'klassci_classe_id' => self::KLASSCI_CLASSE, 'visio_room_id' => 'salon-ailleurs']);
        $this->seance();
        $coordinateur = $this->membre($this->ecole, 'coordinateur');
        $this->app->forgetScopedInstances();

        $lu = app(SeanceVisioEnricher::class)->loadFromLocalDbFallback(self::SEANCE, $coordinateur);

        self::assertNotNull($lu, 'la séance de l\'établissement du compte est rendue');
        self::assertSame('salon-'.self::SEANCE, $lu[0]['visio_room_id']);
    }

    /** L'historique des présences : un élève qui n'est plus de la classe garde sa ligne, sans 500. */
    public function test_l_historique_des_presences_d_un_eleve_sorti_reste_propre(): void
    {
        $seance = $this->seance();
        $eleve = $this->eleve();
        ESBTPAttendance::factory()->create(['seance_id' => $seance->id, 'user_id' => $eleve->id, 'institution_id' => $this->ecole->id]);

        $this->asTenant($eleve)->getJson('/api/lms/attendance/history')->assertOk()
            ->assertJsonPath('success', true);
    }

    /** Les participants visio : un enseignant qui ne gère pas la séance reçoit une réponse propre, sans 500. */
    public function test_les_participants_visio_d_une_seance_non_geree_restent_propres(): void
    {
        $this->seance();

        $reponse = $this->asTenant($this->enseignant(43))->getJson('/api/lms/seances/'.self::SEANCE.'/visio-participants');

        self::assertContains($reponse->status(), [200, 404], 'jamais un 500');
    }

    /** @param  array<string, mixed>  $attributs */
    private function seance(array $attributs = []): Seance
    {
        return Seance::factory()->for($this->ecole)->create($attributs + [
            'klassci_seance_id' => self::SEANCE,
            'klassci_classe_id' => self::KLASSCI_CLASSE,
            'klassci_enseignant_id' => self::ENSEIGNANT_KLASSCI,
            'classe_id' => null,
            'is_active' => true,
            'visio_enabled' => true,
            'visio_room_id' => 'salon-'.self::SEANCE,
        ]);
    }

    private function eleve(): User
    {
        $eleve = $this->membre($this->ecole, 'etudiant');
        $eleve->forceFill(['klassci_token' => 'jeton'])->save();

        return $eleve;
    }

    private function enseignant(int $klassciId): User
    {
        $enseignant = $this->membre($this->ecole, 'enseignant');
        $enseignant->forceFill(['klassci_token' => 'jeton', 'klassci_id' => $klassciId])->save();

        return $enseignant;
    }

    private function url(int $id): string
    {
        return "/api/lms/seances/{$id}/details";
    }
}
