<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * `POST /evaluations/{id}/sync-klassci` : un échec de KLASSCI rendait TOUJOURS un
 * 500 sans motif. Un jeton refusé (401), un refus d'accès, une donnée refusée, et
 * même une panne (qui doit être un 503 avec `Retry-After`) se confondaient : le
 * front, qui lit `reason`, n'avait rien à dire à l'enseignant. La route suit
 * désormais la règle du dépôt (`RendersKlassciBackedErrors::renderKlassciFailure`).
 *
 * Un échec que KLASSCI DÉCLARE en 2xx (`success: false`) était marqué
 * synchronisé : l'évaluation `notes_published`, ses copies `synced_to_klassci`,
 * et un 200 « Notes synchronisées ». Il n'est plus marqué. Le corps réel de
 * cette route n'est pas capturé : seule `success === false` est lue.
 *
 * Seule la frontière réseau est feinte (une closure par requête) ; le contrôleur,
 * le proxy et le client HTTP sont réels, derrière un vrai jeton Sanctum.
 */
final class SyncKlassciEchecMotiveTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ENSEIGNANT = 4242;

    private const EVALUATION_KLASSCI = 9001;

    private User $enseignant;

    private Evaluation $evaluation;

    private EvaluationSubmission $copie;

    private int $envois = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $ecole = Institution::factory()->create(['klassci_api_url' => 'https://klassci.ecole.test']);
        $this->enseignant = User::factory()->for($ecole)->create([
            'role' => 'enseignant',
            'klassci_enseignant_id' => self::ENSEIGNANT,
            'klassci_token' => 'jeton-enseignant',
        ]);
        $this->evaluation = Evaluation::factory()->terminee()->create([
            'institution_id' => $ecole->id,
            'klassci_enseignant_id' => self::ENSEIGNANT,
            'klassci_evaluation_id' => self::EVALUATION_KLASSCI,
            'is_published' => true,
            'notes_published' => false,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $this->evaluation->id,
            'institution_id' => $ecole->id,
            'type' => 'qcm',
        ]);
        $this->copie = EvaluationSubmission::create([
            'evaluation_id' => $this->evaluation->id,
            'klassci_etudiant_id' => 5555,
            'attempt' => 1,
            'status' => 'soumis',
            'started_at' => now(),
            'submitted_at' => now(),
            'note_sur_20' => 12,
            'institution_id' => $ecole->id,
        ]);
    }

    public function test_un_jeton_refuse_par_klassci_rend_401_motive(): void
    {
        $reponse = $this->synchroniserFaceA(fn () => Http::response(['message' => 'Unauthenticated.'], 401));

        $reponse->assertStatus(401)->assertExactJson([
            'success' => false,
            'message' => 'Accès refusé par KLASSCI pour ce compte.',
            'reason' => 'klassci_session_expired',
        ]);
        $this->assertRienNEstMarque();
    }

    #[DataProvider('refusRelayes')]
    public function test_un_refus_de_klassci_est_relaye_avec_son_statut(int $statut, string $message): void
    {
        $reponse = $this->synchroniserFaceA(fn () => Http::response(['message' => 'Refus KLASSCI'], $statut));

        $reponse->assertStatus($statut)->assertExactJson(['success' => false, 'message' => $message]);
        $this->assertRienNEstMarque();
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function refusRelayes(): array
    {
        return [
            'accès refusé (403)' => [403, 'Accès refusé par KLASSCI pour ce compte.'],
            'évaluation inconnue de KLASSCI (404)' => [404, 'Aucune donnée KLASSCI pour ce compte. Vérifiez que le profil correspondant (enseignant, étudiant) y existe bien.'],
            'notes refusées (422)' => [422, 'KLASSCI a refusé la requête : données invalides.'],
        ];
    }

    public function test_une_panne_de_klassci_rend_503_avec_son_delai(): void
    {
        $reponse = $this->synchroniserFaceA(fn () => Http::response(['message' => 'Service Unavailable'], 503));

        $this->assertIndisponible($reponse);
        $this->assertRienNEstMarque();
    }

    public function test_un_echec_declare_par_klassci_n_est_pas_marque_synchronise(): void
    {
        $reponse = $this->synchroniserFaceA(fn () => Http::response(['success' => false, 'message' => 'Notes non enregistrées']));

        $this->assertIndisponible($reponse);
        $this->assertRienNEstMarque();
    }

    public function test_un_enregistrement_reussi_marque_les_notes_synchronisees(): void
    {
        $reponse = $this->synchroniserFaceA(fn () => Http::response(['success' => true, 'data' => ['saved' => 1]]));

        $reponse->assertOk()->assertJsonPath('message', 'Notes synchronisées vers KLASSCI');
        self::assertTrue($this->evaluation->refresh()->notes_published);
        self::assertTrue($this->copie->refresh()->synced_to_klassci);
    }

    /**
     * @param  \Closure(): PromiseInterface  $reponseDeKlassci
     */
    private function synchroniserFaceA(\Closure $reponseDeKlassci): TestResponse
    {
        $factory = new HttpFactory;
        $factory->fake(function (ClientRequest $requete) use ($reponseDeKlassci) {
            if (! str_contains($requete->url(), 'evaluations/'.self::EVALUATION_KLASSCI.'/notes')) {
                return Http::response(['success' => true, 'data' => []]);
            }
            $this->envois++;

            return $reponseDeKlassci();
        });
        $this->app->instance(HttpFactory::class, $factory);

        $reponse = $this->asTenant($this->enseignant)->postJson("/api/evaluations/{$this->evaluation->id}/sync-klassci");

        // Une écriture n'est jamais rejouée : un seul envoi, quel que soit le résultat.
        self::assertSame(1, $this->envois);

        return $reponse;
    }

    private function assertIndisponible(TestResponse $reponse): void
    {
        $reponse->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('reason', 'klassci_unavailable');
    }

    private function assertRienNEstMarque(): void
    {
        self::assertFalse($this->evaluation->refresh()->notes_published, 'L évaluation ne doit pas être déclarée publiée.');
        self::assertFalse($this->copie->refresh()->synced_to_klassci, 'La copie ne doit pas être marquée synchronisée.');
    }
}
