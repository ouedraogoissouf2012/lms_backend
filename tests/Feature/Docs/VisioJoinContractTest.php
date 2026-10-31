<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsVisioSeance;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `POST /lms/seances/{seanceId}/join` : chaque réponse RÉELLE suit
 * ce que `docs/openapi.yaml` en dit, statut et corps.
 *
 * Aucune donnée KLASSCI n'est lue : les deux volets tournent. L'état de la
 * visio (400) est dit avant le droit d'entrer (403) ; le droit est celui de la
 * validation d'un participant, l'appelant étant sa propre cible. Le 429
 * (300 par minute) est décrit, pas testé. Vrai jeton porteur (`asTenant`).
 *
 * @see app/Services/Visio/VisioParticipantSessionService.php
 */
final class VisioJoinContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsVisioSeance;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/seances/{seanceId}/join';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Config::set('services.visio.jitsi.app_id', 'lms-test');
        Config::set('services.visio.jitsi.app_secret', 'secret-de-test-assez-long-pour-hs256');
        Config::set('services.visio.jitsi.audience', 'visio-test');
        Config::set('services.visio.jitsi.domain', 'visio.test');
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function entrants(): iterable
    {
        yield 'élève inscrit' => ['eleve', false];
        yield 'enseignant de la séance' => ['enseignant', true];
        yield 'coordinateur, en observateur' => ['coordinateur', true];
    }

    #[DataProvider('entrants')]
    public function test_200_l_entree_rend_la_cle_du_salon(string $entrant, bool $gereLEnregistrement): void
    {
        $ecole = $this->ecole(false);
        $seance = $this->visio($ecole);
        $compte = match ($entrant) {
            'eleve' => $this->eleveInscrit($ecole),
            'enseignant' => $this->enseignantDeLaSeance($ecole),
            default => $this->compte($ecole, 'coordinateur'),
        };

        $reponse = $this->entrer($compte, $seance->id)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'data');
        $reponse->assertJsonPath('data.visio_token_available', true)
            ->assertJsonPath('data.can_manage_recording', $gereLEnregistrement);
        self::assertIsString($reponse->json('data.visio_token'));
    }

    /** Sans configuration Jitsi : la présence est enregistrée, la clé est nulle. */
    public function test_200_sans_jitsi_la_cle_est_nulle(): void
    {
        Config::set('services.visio.jitsi.app_secret', null);
        $ecole = $this->ecole(false);

        $reponse = $this->entrer($this->compte($ecole, 'coordinateur'), $this->visio($ecole)->id)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonPath('data.visio_token', null)->assertJsonPath('data.visio_token_available', false);
    }

    /** Volet autonome : l'enseignant auteur entre. */
    public function test_200_l_enseignant_auteur_d_une_seance_autonome(): void
    {
        $ecole = $this->ecole(true);
        $auteur = $this->compte($ecole, 'enseignant');

        $reponse = $this->entrer($auteur, $this->visio($ecole, $auteur)->id)->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /** @return iterable<string, array{0: string|null, 1: string, 2: bool}> */
    public static function etatsNonActifs(): iterable
    {
        foreach ([false => 'école KLASSCI', true => 'école autonome'] as $autonome => $volet) {
            yield "programmée, $volet" => ['programmee', 'visio_not_started', (bool) $autonome];
            yield "terminée, $volet" => ['terminee', 'visio_ended', (bool) $autonome];
            yield "jamais activée, $volet" => [null, 'visio_not_enabled', (bool) $autonome];
        }
    }

    /** L'état est dit avant le droit : même un compte sans droit reçoit ce 400. */
    #[DataProvider('etatsNonActifs')]
    public function test_400_la_visio_n_est_pas_active(?string $statut, string $motif, bool $autonome): void
    {
        $ecole = $this->ecole($autonome);
        $seance = $this->visio($ecole, null, ['visio_status' => $statut]);

        $reponse = $this->entrer($this->compte($ecole, 'etudiant'), $seance->id)->assertStatus(400);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'reason');
        $reponse->assertJsonPath('reason', $motif);
    }

    public function test_401_sans_jeton_porteur(): void
    {
        $reponse = $this->postJson('/api/lms/seances/1/join')->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
    }

    public function test_403_un_compte_sans_etablissement(): void
    {
        $compte = User::factory()->create(['institution_id' => null, 'role' => 'etudiant']);

        $this->assertRefus($this->entrer($compte, 1)->assertForbidden(), 'no_institution');
    }

    public function test_403_un_etablissement_desactive(): void
    {
        $ecole = Institution::factory()->create(['is_active' => false]);

        $this->assertRefus($this->entrer($this->compte($ecole, 'etudiant'), 1)->assertForbidden(), null);
    }

    /** @return iterable<string, array{0: string}> */
    public static function motifsDeRefus(): iterable
    {
        foreach (['visio_not_enabled', 'actor_not_authorized', 'not_enrolled'] as $motif) {
            yield $motif => [$motif];
        }
    }

    /** Chaque motif de la décision d'accès, atteint par un vrai chemin (volet KLASSCI). */
    #[DataProvider('motifsDeRefus')]
    public function test_403_la_decision_d_acces(string $motif): void
    {
        $ecole = $this->ecole(false);
        // `visio_not_enabled` : l'état dit `active`, mais la visio a été désactivée.
        $seance = $this->visio($ecole, null, $motif === 'visio_not_enabled' ? ['visio_enabled' => false] : []);
        $compte = $motif === 'actor_not_authorized'
            ? $this->compte($ecole, 'enseignant', ['klassci_enseignant_id' => 1])
            : $this->compte($ecole, 'etudiant');

        $this->assertRefus($this->entrer($compte, $seance->id)->assertForbidden(), $motif);
    }

    /** Volet autonome : la séance n'a pas de classe KLASSCI, l'élève est refusé (défaut G, décrit tel quel). */
    public function test_403_un_eleve_d_une_seance_autonome(): void
    {
        $ecole = $this->ecole(true);
        $seance = $this->visio($ecole, $this->compte($ecole, 'enseignant'));

        $this->assertRefus($this->entrer($this->compte($ecole, 'etudiant'), $seance->id)->assertForbidden(), 'no_classe_id');
    }

    /** @return iterable<string, array{0: string}> */
    public static function introuvables(): iterable
    {
        yield 'identifiant inconnu' => ['inconnu'];
        yield 'séance d une autre école' => ['ailleurs'];
    }

    #[DataProvider('introuvables')]
    public function test_404_aucune_seance_dans_l_etablissement(string $cas): void
    {
        $ecole = $this->ecole(false);
        $seanceId = $cas === 'ailleurs' ? $this->visio($this->ecole(false))->id : 999999;

        $reponse = $this->entrer($this->compte($ecole, 'coordinateur'), $seanceId)->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
    }

    public function test_500_rattrape_par_le_service(): void
    {
        $this->inscriptionIllisible();
        $ecole = $this->ecole(false);

        $reponse = $this->entrer($this->compte($ecole, 'etudiant'), $this->visio($ecole)->id)->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonPath('error', 'Une erreur est survenue.');
    }

    public function test_500_du_gestionnaire_global_sans_error(): void
    {
        $reponse = $this->entrer($this->compte($this->ecole(false), 'coordinateur'), 'abc')->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $reponse->assertJsonMissingPath('error');
    }

    private function entrer(User $compte, int|string $seanceId): TestResponse
    {
        return $this->asTenant($compte)->postJson("/api/lms/seances/{$seanceId}/join");
    }

    /** Le 403 suit le schéma ; son motif (ou son absence) est prouvé à part. */
    private function assertRefus(TestResponse $reponse, ?string $motif): void
    {
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');

        $motif === null ? $reponse->assertJsonMissingPath('reason') : $reponse->assertJsonPath('reason', $motif);
    }
}
