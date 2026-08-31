<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le jeton d'accès rendu par `POST /seances/{id}/join`.
 *
 * ## Pourquoi le jeton vit ICI et pas dans un nouvel endpoint
 *
 * `join` vérifie déjà que la visio est active, que l'appelant a le droit d'y
 * entrer, et enregistre sa présence. L'autorisation est donc établie AU MOMENT
 * DE L'USAGE, ce qui est exactement quand une clé doit être taillée. Créer une
 * route dédiée aurait dupliqué cette surface d'autorisation — c'est-à-dire créé
 * un second endroit où se tromper.
 *
 * ## Ce que ces tests verrouillent
 *
 * Le jeton est la clé de la salle. Trois propriétés doivent tenir :
 *   1. il porte la salle EXACTE de cette séance — jamais une autre, jamais `*` ;
 *   2. le statut de modérateur est décidé par le SERVEUR, jamais réclamé par le
 *      client — sans quoi un élève se déclarerait professeur ;
 *   3. un appelant non autorisé n'obtient aucun jeton, pas même expiré.
 *
 * @see \App\Services\Visio\VisioAccessTokenIssuer
 * @see \Tests\Unit\Services\Visio\VisioAccessTokenIssuerTest
 */
final class JoinVisioAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableKlassciMiddleware();
        Config::set('services.klassci.url', 'https://klassci.test');
        Config::set('services.visio.jitsi.app_id', 'lms-klassci');
        Config::set('services.visio.jitsi.app_secret', self::SECRET);
        Config::set('services.visio.jitsi.audience', 'visio-klassci');
        Config::set('services.visio.jitsi.domain', 'visio.klassci.test');

        $this->institution = Institution::create([
            'slug' => 'school-visio-token',
            'name' => 'School Visio Token',
            'klassci_api_url' => 'https://klassci.test',
            'klassci_api_token_encrypted' => 'token',
            'is_active' => true,
            'settings' => ['timezone' => 'UTC'],
        ]);
        app(TenantManager::class)->set($this->institution);
    }

    // ───────────────────── L'invariant de cloisonnement ─────────────────────

    public function test_the_token_carries_this_seance_room_and_no_other(): void
    {
        $seance = $this->activeSeance();
        $this->fakeEnrolledStudents(['eleve@example.test']);
        Sanctum::actingAs($this->user('etudiant', 'eleve@example.test'));

        $response = $this->postJson("/api/lms/seances/{$seance->id}/join")->assertStatus(200);

        $claims = $this->claims((string) $response->json('data.visio_token'));

        self::assertSame($seance->visio_room_id, $claims['room']);
        self::assertNotSame('*', $claims['room']);
    }

    public function test_a_token_from_one_seance_does_not_carry_another_seance_room(): void
    {
        $a = $this->activeSeance();
        $b = $this->activeSeance();
        $this->fakeEnrolledStudents(['eleve@example.test']);
        Sanctum::actingAs($this->user('etudiant', 'eleve@example.test'));

        $ta = $this->claims((string) $this->postJson("/api/lms/seances/{$a->id}/join")->json('data.visio_token'));
        $tb = $this->claims((string) $this->postJson("/api/lms/seances/{$b->id}/join")->json('data.visio_token'));

        self::assertNotSame($ta['room'], $tb['room']);
    }

    // ───────────────────── Le statut de modérateur ─────────────────────

    /**
     * Un élève qui obtiendrait `moderator: true` pourrait expulser sa classe,
     * couper le micro du professeur et arrêter l'enregistrement.
     */
    public function test_a_student_is_never_a_moderator(): void
    {
        $seance = $this->activeSeance();
        $this->fakeEnrolledStudents(['eleve@example.test']);
        Sanctum::actingAs($this->user('etudiant', 'eleve@example.test'));

        $claims = $this->claims(
            (string) $this->postJson("/api/lms/seances/{$seance->id}/join")->json('data.visio_token')
        );

        self::assertSame('false', $claims['context']['user']['moderator']);
    }

    public function test_a_manager_is_a_moderator(): void
    {
        $seance = $this->activeSeance();
        Sanctum::actingAs($this->user('coordinateur', 'coord@example.test'));

        $claims = $this->claims(
            (string) $this->postJson("/api/lms/seances/{$seance->id}/join")->json('data.visio_token')
        );

        self::assertSame('true', $claims['context']['user']['moderator']);
    }

    // ───────────────────── Aucun jeton sans autorisation ─────────────────────

    public function test_a_refused_participant_receives_no_token_at_all(): void
    {
        $seance = $this->activeSeance();
        $this->fakeEnrolledStudents(['inscrit@example.test']);
        Sanctum::actingAs($this->user('etudiant', 'intrus@example.test'));

        $response = $this->postJson("/api/lms/seances/{$seance->id}/join")->assertStatus(403);

        self::assertNull($response->json('data.visio_token'));
    }

    // ───────────────────── L'identité vient du serveur ─────────────────────

    public function test_the_identity_in_the_token_comes_from_the_account_not_the_request(): void
    {
        $seance = $this->activeSeance();
        $this->fakeEnrolledStudents(['awa@example.test']);
        $user = $this->user('etudiant', 'awa@example.test');
        $user->update(['name' => 'Awa Traoré']);
        Sanctum::actingAs($user);

        // Le client tente d'imposer une autre identité et le statut modérateur.
        $claims = $this->claims((string) $this->postJson(
            "/api/lms/seances/{$seance->id}/join",
            ['name' => 'Directeur', 'moderator' => true, 'email' => 'faux@example.test'],
        )->json('data.visio_token'));

        self::assertSame('Awa Traoré', $claims['context']['user']['name']);
        self::assertSame('awa@example.test', $claims['context']['user']['email']);
        self::assertSame('false', $claims['context']['user']['moderator']);
    }

    // ───────────────────── Configuration absente ─────────────────────

    /**
     * Sans secret, la salle ne s'ouvrirait pas. Mieux vaut le dire que rendre
     * un identifiant de salle qui mène à une porte close.
     */
    public function test_without_a_configured_secret_the_response_says_so(): void
    {
        Config::set('services.visio.jitsi.app_secret', null);
        $seance = $this->activeSeance();
        Sanctum::actingAs($this->user('coordinateur', 'coord@example.test'));

        $response = $this->postJson("/api/lms/seances/{$seance->id}/join")->assertStatus(200);

        self::assertNull($response->json('data.visio_token'));
        self::assertFalse($response->json('data.visio_token_available'));
    }

    // ───────────────────── Le contrat existant est preserve ─────────────────────

    public function test_the_room_id_is_still_returned_as_before(): void
    {
        $seance = $this->activeSeance();
        Sanctum::actingAs($this->user('coordinateur', 'coord@example.test'));

        $this->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.visio_room_id', $seance->visio_room_id);
    }

    // ───────────────────── Utilitaires ─────────────────────

    /**
     * @return array<string, mixed>
     */
    private function claims(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts, "Jeton mal forme : {$token}");

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($json);

        $claims = json_decode($json, true);
        self::assertIsArray($claims);

        // La signature doit tenir : sinon prosody rejetterait sans message.
        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $parts[0].'.'.$parts[1], self::SECRET, true)
        ), '+/', '-_'), '=');
        self::assertSame($expected, $parts[2], 'Signature invalide.');

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    private function user(string $role, string $email): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            'email' => $email,
            'klassci_token' => 'fake-token',
        ]);
    }

    private function activeSeance(): Seance
    {
        return Seance::factory()->visioActive()->create([
            'institution_id' => $this->institution->id,
            'klassci_seance_id' => random_int(10000, 99999),
            'klassci_classe_id' => 55,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<string>  $emails
     */
    private function fakeEnrolledStudents(array $emails): void
    {
        Http::fake([
            'https://klassci.test/classes/55/etudiants' => Http::response([
                'data' => array_map(
                    static fn (string $email): array => ['email' => $email],
                    $emails,
                ),
            ], 200),
        ]);
    }
}
