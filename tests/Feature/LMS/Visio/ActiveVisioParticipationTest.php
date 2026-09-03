<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * `GET /api/lms/visio/active` — la participation en cours de l'appelant (#673).
 *
 * ## À quoi sert cet endpoint
 *
 * La salle de visioconférence est désormais EMBARQUÉE dans le LMS. Un
 * rechargement complet de page la détruit — là où l'onglet séparé y survivait.
 * C'est la régression assumée du chantier, et elle doit être traitée, pas subie.
 *
 * Au démarrage, l'application demande donc au serveur si une participation est
 * ouverte, et remonte la salle le cas échéant. Le serveur fait autorité :
 * aucune persistance côté client, le dépôt ayant délibérément démonté celle qui
 * existait (`visioParticipationCleanup.js`).
 *
 * ## Pourquoi il ne délivre AUCUN jeton
 *
 * Émettre ici une clé de salle dupliquerait la surface d'autorisation de
 * `POST /seances/{id}/join` — soit un second endroit où se tromper. L'endpoint
 * répond « telle séance », et le front rappelle `join`, qui revérifie tout et
 * taille la clé. Un seul point d'autorisation, comme aujourd'hui.
 *
 * ## Pourquoi un jeton porteur RÉEL dans ces tests
 *
 * `Sanctum::actingAs()` n'émet aucun bearer : `ResolveInstitution` ne s'exécute
 * pas, aucun tenant n'est posé, et le scope multi-tenant s'efface. Un test
 * d'isolation écrit ainsi passerait sans rien prouver.
 */
final class ActiveVisioParticipationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/lms/visio/active';

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableKlassciMiddleware();
        Config::set('services.klassci.url', 'https://klassci.test');

        $this->institution = $this->makeInstitution('school-active-visio');
        app(TenantManager::class)->set($this->institution);
    }

    // ───────────────────────── Le comportement nominal ─────────────────────────

    public function test_an_open_participation_is_reported(): void
    {
        $seance = $this->activeSeance($this->institution);
        $user = $this->user($this->institution, 'prof@example.test');
        $this->connect($seance, $user);

        $response = $this->asUser($user)->getJson(self::ENDPOINT)->assertStatus(200);

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.seance_id', $seance->id);
    }

    public function test_no_participation_reports_null_without_failing(): void
    {
        $user = $this->user($this->institution, 'prof@example.test');

        $response = $this->asUser($user)->getJson(self::ENDPOINT)->assertStatus(200);

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data', null);
    }

    /**
     * Une sortie enregistrée ne doit pas faire rentrer l'utilisateur de force à
     * chaque chargement de page.
     */
    public function test_a_closed_participation_is_not_reported(): void
    {
        $seance = $this->activeSeance($this->institution);
        $user = $this->user($this->institution, 'prof@example.test');
        $this->connect($seance, $user, 'disconnected');

        $this->asUser($user)->getJson(self::ENDPOINT)
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    /**
     * LA borne du mécanisme. Sans elle, une ligne restée `connected` — le défaut
     * même que #680 vient de corriger côté enregistrement — ferait remonter la
     * salle à CHAQUE chargement de page, indéfiniment. La fin de la visio est un
     * fait observable ; la fraîcheur du heartbeat n'en est pas un.
     */
    public function test_a_participation_on_an_ended_visio_is_not_reported(): void
    {
        $seance = $this->activeSeance($this->institution);
        $user = $this->user($this->institution, 'prof@example.test');
        $this->connect($seance, $user);

        $seance->update(['visio_status' => 'terminee', 'visio_active' => false]);

        $this->asUser($user)->getJson(self::ENDPOINT)
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    // ───────────────────────── Le cloisonnement ─────────────────────────

    public function test_the_participation_of_another_user_is_never_reported(): void
    {
        $seance = $this->activeSeance($this->institution);
        $autre = $this->user($this->institution, 'autre@example.test');
        $this->connect($seance, $autre);

        $moi = $this->user($this->institution, 'moi@example.test');

        $this->asUser($moi)->getJson(self::ENDPOINT)
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    /**
     * Deux établissements peuvent partager une adresse e-mail. Une participation
     * ne doit jamais traverser la frontière d'institution.
     */
    public function test_a_participation_from_another_institution_is_never_reported(): void
    {
        $autreEcole = $this->makeInstitution('school-voisine');
        $seanceVoisine = $this->activeSeance($autreEcole);
        $profVoisin = $this->user($autreEcole, 'prof@example.test');
        $this->connect($seanceVoisine, $profVoisin);

        $moi = $this->user($this->institution, 'prof@example.test');

        $this->asUser($moi)->getJson(self::ENDPOINT)
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    /**
     * Aucun identifiant fourni par le client ne doit être accepté : la
     * participation se résout par l'utilisateur authentifié, et par lui seul.
     */
    public function test_no_client_supplied_identifier_can_widen_the_result(): void
    {
        $seance = $this->activeSeance($this->institution);
        $autre = $this->user($this->institution, 'autre@example.test');
        $this->connect($seance, $autre);

        $moi = $this->user($this->institution, 'moi@example.test');

        $this->asUser($moi)
            ->getJson(self::ENDPOINT . '?user_id=' . $autre->id . '&seance_id=' . $seance->id)
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    // ───────────────────────── Fixtures ─────────────────────────

    private function makeInstitution(string $slug): Institution
    {
        return Institution::create([
            'slug' => $slug,
            'name' => $slug,
            'klassci_api_url' => 'https://klassci.test',
            'klassci_api_token_encrypted' => 'token',
            'is_active' => true,
            'settings' => ['timezone' => 'UTC'],
        ]);
    }

    private function activeSeance(Institution $institution): Seance
    {
        return Seance::factory()->visioActive()->create([
            'institution_id' => $institution->id,
        ]);
    }

    private function user(Institution $institution, string $email): User
    {
        return User::factory()->create([
            'institution_id' => $institution->id,
            'email' => $email,
            'role' => 'enseignant',
        ]);
    }

    private function connect(Seance $seance, User $user, string $status = 'connected'): ESBTPAttendance
    {
        return ESBTPAttendance::create([
            'seance_id' => $seance->id,
            'user_id' => $user->id,
            'institution_id' => $seance->institution_id,
            'nom' => $user->name,
            'prenom' => '',
            'email' => $user->email,
            'joined_at' => now(),
            'last_seen_at' => now(),
            'status' => $status,
            'is_validated' => true,
        ]);
    }

    /** Jeton porteur RÉEL — sans lui, aucun tenant n'est résolu (cf. docblock). */
    private function asUser(User $user): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('active-visio-test')->plainTextToken,
        ]);
    }
}
