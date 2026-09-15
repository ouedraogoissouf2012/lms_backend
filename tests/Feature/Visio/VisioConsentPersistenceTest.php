<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Enums\ConsentPurpose;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use App\Services\Visio\Recording\RecordingConsentGuard;
use App\Services\Visio\Recording\SeanceRecordingControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le consentement visio se donne, et se persiste (#716 ↔ front #333).
 *
 * ## Les deux moitiés n'avaient jamais été raccordées
 *
 * Le frontend (#333) recueille les trois finalités dans un écran de réglages,
 * puis les range dans `localStorage` — et le dit lui-même, dans le docblock de
 * `useVisioConsent.js` : « Persistance locale scopée (cacheKey) **en attendant
 * lms_backend#716** ».
 *
 * Le backend (#716) a livré la table `consents`, le garde append-only et
 * `RecordingConsentGuard::record()`. Mais AUCUNE route ne mène à `record()`, et
 * aucun service ne l'appelle : vérifié sur tout le dépôt, seuls ses propres
 * tests l'atteignent.
 *
 * Le front attend le backend ; le backend attend une ligne que rien n'écrit.
 *
 * ## Ce que ça produisait
 *
 * `allowsStart()` rendait donc TOUJOURS `false`, et tout enregistrement
 * repartait en 422. Le miroir côté front avale cette erreur — délibérément,
 * pour ne pas casser la salle en plein cours — si bien que Jibri enregistrait
 * réellement, puis le webhook de fin ne trouvait aucune ligne active et rendait
 * 404 : la vidéo finissait orpheline sur le disque.
 *
 * Depuis le 6 septembre 2026, date de déploiement de #716, l'enregistrement
 * était donc impossible. C'est ce qui empêchait #469 de se fermer.
 *
 * ## Ce que ces tests verrouillent
 *
 * Le garde n'est PAS assoupli : sans consentement, on refuse toujours — c'est
 * la décision de #716, et `RecordingConsentGuardTest::test_start_without_consent_is_rejected`
 * la protège. On ouvre seulement la porte qui permet de le donner.
 */
final class VisioConsentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);

        $this->teacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_id' => 7161,
            'klassci_enseignant_id' => 7161,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /**
     * LE raccord manquant : déposer son consentement le persiste côté serveur.
     */
    public function test_a_teacher_can_record_their_consent_server_side(): void
    {
        $reponse = $this->actingAsTeacher()->postJson('/api/lms/visio/consent', [
            'captation' => true,
            'diffusion' => true,
            'reutilisation' => false,
        ]);

        $reponse->assertOk();

        self::assertDatabaseHas('consents', [
            'user_id' => $this->teacher->id,
            'institution_id' => $this->institution->id,
            'purpose' => ConsentPurpose::Capture->value,
            'granted' => true,
        ]);
        self::assertDatabaseHas('consents', [
            'user_id' => $this->teacher->id,
            'purpose' => ConsentPurpose::Reutilisation->value,
            'granted' => false,
        ]);
    }

    /**
     * La conséquence qui compte : une fois le consentement donné, l'enregistrement
     * n'est plus refusé. C'est exactement ce qui bloquait #469.
     */
    public function test_recording_is_no_longer_refused_once_consent_is_given(): void
    {
        $seance = $this->seanceDeLEnseignant();

        // Avant : le garde refuse — comportement voulu par #716.
        self::assertFalse(app(RecordingConsentGuard::class)->allowsStart($seance, $this->teacher));

        $this->actingAsTeacher()->postJson('/api/lms/visio/consent', [
            'captation' => true,
            'diffusion' => true,
            'reutilisation' => false,
        ])->assertOk();

        self::assertTrue(
            app(RecordingConsentGuard::class)->allowsStart($seance, $this->teacher),
            'le consentement depose doit debloquer le demarrage',
        );

        $resultat = app(SeanceRecordingControlService::class)->start($seance->id, $this->teacher);

        self::assertSame(200, $resultat['status'], 'apres consentement, start() ne doit plus rendre 422');
        self::assertDatabaseCount('seance_recordings', 1);
    }

    /**
     * Le consentement est un réglage de COMPTE, pas de séance : il vaut pour
     * toutes les séances de l'enseignant. Le garde le gère déjà nativement
     * (`seance_id = X OR seance_id IS NULL`).
     */
    public function test_consent_given_once_covers_every_seance(): void
    {
        $this->actingAsTeacher()->postJson('/api/lms/visio/consent', [
            'captation' => true,
            'diffusion' => false,
            'reutilisation' => false,
        ])->assertOk();

        $guard = app(RecordingConsentGuard::class);

        foreach ([$this->seanceDeLEnseignant(), $this->seanceDeLEnseignant()] as $seance) {
            self::assertTrue($guard->allowsStart($seance, $this->teacher));
        }
    }

    /**
     * Révoquer n'efface rien : la table est append-only (#716). L'état courant
     * change, l'historique reste — sur des enregistrements de mineurs, « qui a
     * consenti, et quand » ne doit jamais devenir irrécupérable.
     */
    public function test_revoking_appends_and_never_erases(): void
    {
        $this->actingAsTeacher()->postJson('/api/lms/visio/consent', [
            'captation' => true, 'diffusion' => true, 'reutilisation' => true,
        ])->assertOk();

        $this->actingAsTeacher()->postJson('/api/lms/visio/consent', [
            'captation' => false, 'diffusion' => false, 'reutilisation' => false,
        ])->assertOk();

        // Trois finalites x deux depots : rien n est ecrase, tout s empile.
        self::assertDatabaseCount('consents', 6);
        self::assertFalse(
            app(RecordingConsentGuard::class)->allowsStart($this->seanceDeLEnseignant(), $this->teacher),
        );
    }

    /**
     * L'état courant est lisible, pour que le frontend cesse de dépendre de
     * `localStorage` — un stockage que l'utilisateur perd en changeant de poste,
     * et qui ne prouve rien en cas de contrôle.
     */
    public function test_the_current_state_can_be_read_back(): void
    {
        $this->actingAsTeacher()->postJson('/api/lms/visio/consent', [
            'captation' => true, 'diffusion' => false, 'reutilisation' => false,
        ])->assertOk();

        $this->actingAsTeacher()->getJson('/api/lms/visio/consent')
            ->assertOk()
            ->assertJsonPath('data.captation', true)
            ->assertJsonPath('data.diffusion', false)
            ->assertJsonPath('data.reutilisation', false);
    }

    /**
     * Un consentement ne se donne pas pour autrui : la ligne porte toujours le
     * demandeur authentifié, jamais un identifiant reçu du client.
     */
    public function test_consent_is_always_recorded_for_the_authenticated_user(): void
    {
        $autre = User::factory()->teacher()->for($this->institution)->create();

        $this->actingAsTeacher()->postJson('/api/lms/visio/consent', [
            'captation' => true,
            'diffusion' => false,
            'reutilisation' => false,
            'user_id' => $autre->id,
        ])->assertOk();

        self::assertDatabaseMissing('consents', ['user_id' => $autre->id]);
        self::assertDatabaseHas('consents', ['user_id' => $this->teacher->id]);
    }

    /**
     * Un compte hors etablissement est refuse PROPREMENT.
     *
     * `consents.institution_id` est `constrained()`, donc NOT NULL : sans ce
     * garde, un supradmin declencherait une violation de contrainte, c est-a-dire
     * un 500 la ou un refus explicite est la bonne reponse.
     */
    public function test_an_account_without_institution_is_refused_not_crashed(): void
    {
        $horsEtablissement = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);

        $this->withToken($horsEtablissement->createToken('t')->plainTextToken)
            ->postJson('/api/lms/visio/consent', [
                'captation' => true, 'diffusion' => true, 'reutilisation' => true,
            ])
            ->assertStatus(422);

        self::assertDatabaseCount('consents', 0);
    }

    /**
     * Les trois finalites sont OBLIGATOIRES : un consentement partiellement
     * renseigne n a pas de sens juridique, et un defaut implicite en a encore
     * moins.
     */
    public function test_a_partial_payload_is_refused(): void
    {
        $this->actingAsTeacher()
            ->postJson('/api/lms/visio/consent', ['captation' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['diffusion', 'reutilisation']);

        self::assertDatabaseCount('consents', 0);
    }

    // ───────────────────── Fixtures ─────────────────────

    /**
     * Un VRAI jeton, jamais `Sanctum::actingAs()` : sans bearer, aucun tenant
     * n'est résolu et le scope multi-établissement s'efface silencieusement.
     */
    private function actingAsTeacher(): self
    {
        return $this->withToken($this->teacher->createToken('test')->plainTextToken);
    }

    private function seanceDeLEnseignant(): Seance
    {
        return Seance::factory()->forInstitution($this->institution)->visioActive()->create([
            'klassci_enseignant_id' => 7161,
        ]);
    }
}
