<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Enums\InstitutionMode;
use App\Enums\LessonStatus;
use App\Enums\SeanceRecordingStatus;
use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #1618 — le parcours d'une école KLASSCI, tel qu'il se montre en démonstration.
 *
 * KLASSCI est simulé au niveau HTTP (`Http::fake`, et `preventStrayRequests` : tout appel non prévu
 * échoue). Les vrais clients et le vrai proxy tournent donc comme en production. Chaque rôle se
 * connecte par la vraie route `/api/auth/login`, puis est servi par le jeton que cette route lui rend.
 *
 * Étapes :
 * 1. le supradmin vérifie la connexion de l'école à KLASSCI ;
 * 2. les quatre rôles se connectent par KLASSCI ;
 * 3. l'enseignant active, démarre et enregistre la visio de sa séance KLASSCI ; l'enregistrement
 *    devient un chapitre de son cours, et l'élève de la classe le lit.
 */
final class ParcoursEcoleKlassciTest extends TestCase
{
    use RefreshDatabase;

    private const KLASSCI = 'https://demo.klassci.test/api/lms';

    private const SEANCE = 90501;

    private const MATIERE = 7701;

    private const CLASSE = 3301;

    private const SECRET_WEBHOOK = 'secret-parcours-1618';

    private const VIDEO = 'https://enregistrements.example.test/parcours-1618.mp4';

    /** Les comptes de l'école chez KLASSCI : identifiant => [id KLASSCI, rôle KLASSCI, rôle LMS attendu]. */
    private const COMPTES = [
        'admin.demo' => [9101, 'admin', 'admin'],
        'coord.demo' => [9102, 'coordinateur', 'coordinateur'],
        'prof.demo' => [9103, 'enseignant', 'enseignant'],
        'eleve.demo' => [9104, 'etudiant', 'etudiant'],
    ];

    private Institution $ecole;

    /** @var list<string> Les appels HTTP qui ne vont pas vers KLASSCI : aucun n'est prévu. */
    private array $appelsHorsKlassci = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.visio.webhook_secret' => self::SECRET_WEBHOOK, 'queue.default' => 'sync']);
        $this->ecole = Institution::factory()->create([
            'slug' => 'demo-klassci',
            'is_active' => true,
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => self::KLASSCI,
        ]);
        Http::preventStrayRequests();
        Http::fake(fn (Request $requete) => $this->klassci($requete));
    }

    public function test_1_le_supradmin_verifie_la_connexion_de_l_ecole_a_klassci(): void
    {
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $this->withToken($supradmin->createToken('parcours')->plainTextToken)
            ->postJson("/api/admin/institutions/{$this->ecole->id}/test-connection")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_2_les_quatre_roles_se_connectent_par_klassci(): void
    {
        foreach (self::COMPTES as $identifiant => [, , $roleLms]) {
            $connexion = $this->connecter($identifiant);

            $connexion->assertOk()->assertJsonPath('data.user.role', $roleLms);
            $this->avecLeJeton($connexion)->getJson('/api/auth/me')->assertOk();
        }
    }

    public function test_3_l_enseignant_enregistre_sa_seance_et_l_eleve_lit_le_chapitre(): void
    {
        $prof = $this->connecter('prof.demo')->assertOk();
        $eleve = $this->connecter('eleve.demo')->assertOk();
        $lecon = $this->leconDuProf($this->compte('prof.demo'));

        foreach (['activate-visio', 'start-visio', 'recording/start', 'recording/stop'] as $geste) {
            $this->avecLeJeton($prof)->postJson('/api/lms/seances/'.self::SEANCE."/{$geste}")
                ->assertOk();
        }

        $enregistrement = SeanceRecording::query()
            ->where('seance_id', Seance::query()->where('klassci_seance_id', self::SEANCE)->value('id'))
            ->latest('id')->firstOrFail();
        self::assertSame(SeanceRecordingStatus::Processing, $enregistrement->status);

        $this->fournisseurAnnonce($enregistrement)->assertStatus(202);

        $chapitre = Chapter::query()->findOrFail($enregistrement->refresh()->chapter_id);
        self::assertSame($lecon->id, $chapitre->lesson_id);
        $this->avecLeJeton($eleve)->getJson("/api/chapters/{$chapitre->id}")
            ->assertOk()
            ->assertJsonPath('data.video_url', self::VIDEO);
    }

    private function connecter(string $identifiant): TestResponse
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['username' => $identifiant, 'password' => 'mot-de-passe-demo']);
    }

    private function avecLeJeton(TestResponse $connexion): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $connexion->json('data.token'));
    }

    private function compte(string $identifiant): User
    {
        return User::withoutGlobalScopes()->where('klassci_id', self::COMPTES[$identifiant][0])->firstOrFail();
    }

    /** Le cours publié de l'enseignant, dans la matière et la classe de sa séance KLASSCI. */
    private function leconDuProf(User $prof): Lesson
    {
        $matiere = Matiere::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => self::MATIERE]);
        $classe = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => self::CLASSE]);

        return Lesson::factory()->create([
            'institution_id' => $this->ecole->id,
            'matiere_id' => $matiere->id,
            'classe_id' => $classe->id,
            'enseignant_id' => $prof->id,
            'status' => LessonStatus::Published,
        ]);
    }

    private function fournisseurAnnonce(SeanceRecording $enregistrement): TestResponse
    {
        $this->flushHeaders();
        $corps = ['recording_id' => $enregistrement->id, 'url' => self::VIDEO, 'provider' => 'external'];
        $horodatage = (string) time();
        $brut = json_encode($corps, JSON_THROW_ON_ERROR);

        return $this->postJson('/api/webhooks/visio/recording-ready', $corps, [
            'X-Visio-Signature' => 'sha256='.hash_hmac('sha256', $horodatage."\nparcours-1618\n".$brut, self::SECRET_WEBHOOK),
            'X-Visio-Timestamp' => $horodatage,
            'X-Visio-Nonce' => 'parcours-1618',
        ]);
    }

    protected function tearDown(): void
    {
        self::assertSame([], $this->appelsHorsKlassci, 'Appels HTTP hors de KLASSCI, non simulés.');
        parent::tearDown();
    }

    /** KLASSCI simulé : chaque appel du LMS reçoit la réponse que KLASSCI lui donnerait. */
    private function klassci(Request $requete): mixed
    {
        if (! str_starts_with($requete->url(), self::KLASSCI)) {
            $this->appelsHorsKlassci[] = $requete->method().' '.$requete->url();

            return Http::response(['parcours' => 'appel non simulé'], 599);
        }
        $chemin = substr($requete->url(), strlen(self::KLASSCI));
        $chemin = (string) strtok($chemin, '?');

        return match (true) {
            $chemin === '' || $chemin === '/' => Http::response(['success' => true], 200),
            $chemin === '/auth/check-user' => Http::response(['success' => true, 'data' => ['found' => true]], 200),
            $chemin === '/auth/login' => $this->klassciConnecte($requete),
            $chemin === '/me/teacher-dashboard' => Http::response(['success' => true, 'data' => ['matieres' => [self::matiereKlassci()]]], 200),
            $chemin === '/matieres' => Http::response(['success' => true, 'data' => [self::matiereKlassci()]], 200),
            $chemin === '/emploi-temps' => Http::response(['success' => true, 'data' => [self::seanceKlassci()]], 200),
            $chemin === '/me/dashboard' => Http::response(['success' => true, 'data' => ['classe' => ['id' => self::CLASSE, 'name' => 'BTS 1 Démo']]], 200),
            default => Http::response(['success' => true, 'data' => []], 200),
        };
    }

    private function klassciConnecte(Request $requete): mixed
    {
        $identifiant = (string) ($requete->data()['username'] ?? '');
        if (! isset(self::COMPTES[$identifiant])) {
            return Http::response(['success' => false, 'message' => 'Identifiants invalides'], 401);
        }
        [$id, $role] = self::COMPTES[$identifiant];

        return Http::response(['success' => true, 'data' => [
            'user' => ['id' => $id, 'email' => $identifiant.'@demo-klassci.test', 'name' => ucfirst($identifiant), 'role' => $role],
            'token' => 'jeton-klassci-'.$id,
        ], 'meta' => []], 200);
    }

    /** @return array<string, mixed> */
    private static function matiereKlassci(): array
    {
        return ['id' => self::MATIERE, 'nom' => 'Génie civil'];
    }

    /** @return array<string, mixed> La séance du jour, en cours : la visio peut démarrer. */
    private static function seanceKlassci(): array
    {
        return [
            'id' => self::SEANCE,
            'matiere' => self::matiereKlassci(),
            'classe' => ['id' => self::CLASSE, 'libelle' => 'BTS 1 Démo'],
            'programmation' => [
                'date_seance' => now()->format('Y-m-d'),
                'heure_debut' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
                'heure_fin' => now()->addHour()->format('Y-m-d H:i:s'),
            ],
            'enseignant' => ['id' => self::COMPTES['prof.demo'][0]],
        ];
    }
}
