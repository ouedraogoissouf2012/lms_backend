<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Enums\InstitutionMode;
use App\Enums\TrainingSessionStatus;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1184 — décision du user du 17/11 : les sessions de formation (un programme, puis
 * des périodes datées) n'existent que dans une école AUTONOME. Dans une école
 * KLASSCI, tout cela vient de KLASSCI : le LMS « récupère, pas plus pas moins ».
 *
 * Une session de formation est l'offre pédagogique de l'école : elle passe par la
 * porte du catalogue local (#910), comme les classes, les matières et les codes.
 * Son refus porte désormais un motif, UN pour toute la porte :
 * `catalogue_managed_by_klassci`.
 *
 * La connexion expose la capacité au front : `meta.peut_gerer_catalogue_local`.
 *
 * Vrai jeton, les deux types d'école.
 */
final class SessionsReserveesALEcoleAutonomeTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const MOTIF = 'catalogue_managed_by_klassci';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_une_ecole_klassci_ne_cree_ni_programme_ni_periode_et_n_en_lit_aucune(): void
    {
        [$ecole, $gestion] = $this->ecoleEtGestion(InstitutionMode::Klassci);
        $programme = Program::factory()->create(['institution_id' => $ecole->id]);

        $this->assertRefus($this->appeler($gestion, 'POST', '/api/programs', ['titre' => 'Bureautique']));
        $this->assertRefus($this->appeler($gestion, 'POST', '/api/training-sessions', $this->chargePeriode($programme)));
        $this->assertRefus($this->appeler($gestion, 'GET', '/api/training-sessions'));

        self::assertSame(1, Program::query()->withoutGlobalScopes()->count(), 'Aucun programme créé.');
        self::assertSame(0, TrainingSession::query()->withoutGlobalScopes()->count(), 'Aucune période créée.');
    }

    public function test_une_ecole_klassci_ne_fait_avancer_aucune_periode(): void
    {
        [$ecole, $gestion] = $this->ecoleEtGestion(InstitutionMode::Klassci);
        $periode = $this->periode($ecole);

        foreach (['publier', 'annuler', 'cloturer', 'archiver'] as $operation) {
            $this->assertRefus($this->appeler($gestion, 'POST', "/api/training-sessions/{$periode->id}/{$operation}"));
        }

        self::assertSame(TrainingSessionStatus::Brouillon, $periode->fresh()?->status, 'Le statut ne change pas.');
    }

    /** Verrou : l'école autonome crée, publie et clôt, comme avant. */
    public function test_une_ecole_autonome_cree_publie_et_clot(): void
    {
        [, $gestion] = $this->ecoleEtGestion(InstitutionMode::Standalone);

        $programme = $this->appeler($gestion, 'POST', '/api/programs', ['titre' => 'Bureautique'])->assertCreated()->json('data.id');
        $periode = $this->appeler($gestion, 'POST', '/api/training-sessions', $this->chargePeriode(Program::findOrFail($programme)))
            ->assertCreated()->json('data.id');

        $this->appeler($gestion, 'POST', "/api/training-sessions/{$periode}/publier")->assertOk();
        $this->appeler($gestion, 'POST', "/api/training-sessions/{$periode}/cloturer")->assertOk();
        $this->appeler($gestion, 'GET', '/api/training-sessions')->assertOk();
    }

    /** Le motif est celui de TOUTE la porte : une route déjà gardée le porte aussi. */
    public function test_le_motif_vaut_pour_toute_la_porte_du_catalogue(): void
    {
        [, $gestion] = $this->ecoleEtGestion(InstitutionMode::Klassci);

        $this->assertRefus($this->appeler($gestion, 'POST', '/api/classes', ['libelle' => '6e A']));
    }

    /** La connexion dit au front ce que le compte peut faire du catalogue. */
    public function test_la_connexion_expose_la_capacite_catalogue(): void
    {
        foreach ([[InstitutionMode::Standalone, true], [InstitutionMode::Klassci, false]] as [$mode, $attendu]) {
            $ecole = Institution::factory()->create(['mode' => $mode, 'is_active' => true]);
            $email = 'gestion-'.$mode->value.'@ecole.test';
            User::factory()->for($ecole)->create([
                'role' => 'coordinateur',
                'email' => $email,
                'password' => 'mot-de-passe-1184',
                'klassci_id' => null,
                'klassci_token' => null,
            ]);

            $this->postJson('/api/auth/login', ['username' => $email, 'password' => 'mot-de-passe-1184'])
                ->assertOk()
                ->assertJsonPath('meta.peut_gerer_catalogue_local', $attendu);
        }
    }

    /**
     * La voie KLASSCI de la connexion porte la même capacité : le compte d'une école
     * KLASSCI ne gère pas le catalogue dans le LMS.
     */
    public function test_la_connexion_klassci_expose_la_capacite_catalogue(): void
    {
        $ecole = Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'slug' => 'presentation',
            'klassci_api_url' => 'https://presentation.klassci.test/api/lms',
            'is_active' => true,
        ]);
        $compte = User::factory()->for($ecole)->create([
            'role' => 'coordinateur',
            'email' => 'gestion@klassci.test',
            'password' => 'mot-de-passe-1184',
            'klassci_id' => 200001,
            'klassci_token' => 'jeton-expire',
        ]);
        $this->mock(KlassciAuthClient::class, fn ($mock) => $mock->shouldReceive('attemptLogin')->once()->andReturn([
            'data' => ['user' => ['id' => 200001, 'email' => $compte->email, 'nom' => $compte->name, 'role' => 'coordinateur'], 'token' => 'jeton-frais'],
            'meta' => [],
        ]));
        $this->mock(KlassciUserSynchronizer::class, fn ($mock) => $mock->shouldReceive('sync')->once()->andReturn($compte));

        // Le chemin KLASSCI, et non le repli local.
        $this->postJson('/api/auth/login', ['username' => 'gestion@klassci.test', 'password' => 'mot-de-passe-1184'])
            ->assertOk()
            ->assertJsonPath('meta.klassci_synced', true)
            ->assertJsonPath('meta.peut_gerer_catalogue_local', false);
    }

    /** @return array{0: Institution, 1: User} */
    private function ecoleEtGestion(InstitutionMode $mode): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return [$ecole, User::factory()->for($ecole)->create(['role' => 'coordinateur', 'last_klassci_sync' => now()])];
    }

    private function periode(Institution $ecole): TrainingSession
    {
        return TrainingSession::factory()->create([
            'institution_id' => $ecole->id,
            'program_id' => Program::factory()->create(['institution_id' => $ecole->id])->id,
            'status' => TrainingSessionStatus::Brouillon,
            'starts_on' => now()->addMonth()->toDateString(),
        ]);
    }

    /** @return array<string, mixed> */
    private function chargePeriode(Program $programme): array
    {
        return [
            'program_id' => $programme->id,
            'libelle' => 'Promotion de janvier',
            'starts_on' => now()->addMonth()->toDateString(),
            'ends_on' => now()->addMonths(4)->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return TestResponse<Response>
     */
    private function appeler(User $compte, string $methode, string $uri, array $donnees = []): TestResponse
    {
        return $this->asTenant($compte)->json($methode, $uri, $donnees);
    }

    /** @param  TestResponse<Response>  $reponse */
    private function assertRefus(TestResponse $reponse): void
    {
        self::assertSame(403, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame(self::MOTIF, $reponse->json('reason'));
    }
}
