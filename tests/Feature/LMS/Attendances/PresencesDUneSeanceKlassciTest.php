<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Attendances;

use App\Enums\InstitutionMode;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * `GET /lms/seances/{id}/attendances` (#1098).
 *
 * École KLASSCI : une séance servie par l'emploi du temps, sans ligne locale, est
 * trouvée comme le détail la trouve (`EmploiTempsSeanceLocator`, #1094) ; ses présences
 * sont vides, puisqu'elles sont attachées à la ligne locale. Une séance inconnue reste
 * introuvable. L'ordre de recherche des lignes locales est inchangé.
 *
 * Les présences suivent la règle d'accès du détail de séance (#1078) : la gestion de
 * l'école, ou l'auteur de la séance.
 *
 * Seule la frontière réseau est feinte (une closure par requête) ; vrai jeton Sanctum.
 */
final class PresencesDUneSeanceKlassciTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const TENANT_URL = 'https://klassci.ecole.test';

    private const SEANCE = 9701;

    private const MATIERE = 3;

    /** @var array<string, int> appels reçus, par chemin KLASSCI */
    private array $appels = [];

    /** La matière que liste le tableau de bord du formateur. */
    private int $matiereDuFormateur = self::MATIERE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return array<string, array{0: string}> */
    public static function gestion(): array
    {
        return ['coordinateur' => ['coordinateur'], 'superAdmin' => ['superAdmin']];
    }

    #[DataProvider('gestion')]
    public function test_une_seance_klassci_sans_ligne_locale_rend_une_liste_vide(string $role): void
    {
        $ecole = $this->ecoleKlassci();
        $this->klassciTelQuIlRepond();

        $this->asTenant($this->membre($ecole, $role))
            ->getJson('/api/lms/seances/'.self::SEANCE.'/attendances')
            ->assertOk()
            ->assertJsonPath('seance.klassci_seance_id', self::SEANCE)
            ->assertJsonPath('seance.id', null)
            ->assertJsonPath('seance.matiere_nom', 'Anglais')
            ->assertJsonPath('statistics.total_participants', 0)
            ->assertJsonPath('attendances', []);
        self::assertSame(1, $this->appels['emploi-temps'] ?? 0);
        self::assertSame(0, $this->appels['matieres/{id}'] ?? 0);
    }

    public function test_le_formateur_de_la_matiere_la_trouve(): void
    {
        $ecole = $this->ecoleKlassci();
        $this->klassciTelQuIlRepond();

        $this->asTenant($this->membre($ecole, 'enseignant'))
            ->getJson('/api/lms/seances/'.self::SEANCE.'/attendances')
            ->assertOk()
            ->assertJsonPath('attendances', []);
    }

    public function test_le_formateur_d_une_autre_matiere_ne_la_trouve_pas(): void
    {
        $ecole = $this->ecoleKlassci();
        $this->matiereDuFormateur = 5;
        $this->klassciTelQuIlRepond();

        $this->asTenant($this->membre($ecole, 'enseignant'))
            ->getJson('/api/lms/seances/'.self::SEANCE.'/attendances')
            ->assertNotFound();
    }

    public function test_une_seance_inconnue_reste_introuvable(): void
    {
        $ecole = $this->ecoleKlassci();
        $this->klassciTelQuIlRepond();

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson('/api/lms/seances/424242/attendances')
            ->assertNotFound();
    }

    public function test_une_seance_avec_ligne_locale_garde_ses_presences(): void
    {
        $ecole = $this->ecoleKlassci();
        $this->klassciTelQuIlRepond();
        $ligne = Seance::factory()->for($ecole)->create(['klassci_seance_id' => self::SEANCE]);
        ESBTPAttendance::factory()->create(['seance_id' => $ligne->id, 'institution_id' => $ecole->id, 'duration_minutes' => 30]);

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson('/api/lms/seances/'.self::SEANCE.'/attendances')
            ->assertOk()
            ->assertJsonPath('seance.id', $ligne->id)
            ->assertJsonCount(1, 'attendances');
        self::assertSame(0, $this->appels['emploi-temps'] ?? 0, 'La ligne locale suffit : KLASSCI n est pas appelé.');
    }

    public function test_la_gestion_d_une_ecole_autonome_lit_les_presences(): void
    {
        [$ecole, $seance] = $this->seanceAutonome();

        $this->asTenant($this->membre($ecole, 'coordinateur', null))
            ->getJson("/api/lms/seances/{$seance->id}/attendances")
            ->assertOk()
            ->assertJsonPath('seance.id', $seance->id);
    }

    public function test_l_auteur_d_une_seance_autonome_lit_ses_presences(): void
    {
        [$ecole, $seance, $auteur] = $this->seanceAutonome();

        $this->asTenant($auteur)
            ->getJson("/api/lms/seances/{$seance->id}/attendances")
            ->assertOk();
    }

    public function test_le_formateur_non_auteur_recoit_403(): void
    {
        [$ecole, $seance] = $this->seanceAutonome();

        $this->asTenant($this->membre($ecole, 'enseignant', null))
            ->getJson("/api/lms/seances/{$seance->id}/attendances")
            ->assertForbidden();
    }

    private function ecoleKlassci(): Institution
    {
        return Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => self::TENANT_URL,
        ]);
    }

    /** @return array{0: Institution, 1: Seance, 2: User} */
    private function seanceAutonome(): array
    {
        $ecole = Institution::factory()->standalone()->create();
        $auteur = $this->membre($ecole, 'enseignant', null);
        $seance = Seance::factory()->for($ecole)->create([
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'created_by' => $auteur->id,
        ]);

        return [$ecole, $seance, $auteur];
    }

    private function membre(Institution $ecole, string $role, ?string $jeton = 'jeton-klassci'): User
    {
        $klassci = $jeton !== null;

        return User::factory()->for($ecole)->create([
            'role' => $role,
            'klassci_token' => $jeton,
            'klassci_tenant_url' => $klassci ? self::TENANT_URL : null,
            // Un compte sans identité KLASSCI : le cas de l'école autonome.
            'klassci_id' => null,
            'klassci_enseignant_id' => null,
            'last_klassci_sync' => now(),
        ]);
    }

    /** Le catalogue, le tableau de bord du formateur et `emploi-temps`, aux clés de la capture. */
    private function klassciTelQuIlRepond(): void
    {
        $factory = new HttpFactory;
        $factory->fake(function (ClientRequest $requete): PromiseInterface {
            $chemin = (string) parse_url($requete->url(), PHP_URL_PATH);
            if (preg_match('#/matieres/\d+$#', $chemin) === 1) {
                $this->compter('matieres/{id}');

                return Http::response(['success' => true, 'data' => ['seances_programmees' => []]]);
            }
            if (str_ends_with($chemin, '/matieres')) {
                return Http::response(['success' => true, 'data' => [['id' => self::MATIERE, 'nom' => 'Anglais', 'code' => 'ANG']]]);
            }
            if (str_ends_with($chemin, '/me/teacher-dashboard')) {
                return Http::response(['success' => true, 'data' => ['matieres' => [['id' => $this->matiereDuFormateur, 'nom' => 'Matière']]]]);
            }
            if (str_ends_with($chemin, '/emploi-temps')) {
                $this->compter('emploi-temps');

                return Http::response(['success' => true, 'data' => [[
                    'id' => self::SEANCE,
                    'matiere' => ['id' => self::MATIERE, 'nom' => 'Anglais'],
                    'classe' => ['id' => 4, 'nom' => 'Classe'],
                    'enseignant' => ['id' => 77, 'teacher_id' => 88, 'nom' => 'Awa Enseignante'],
                    'programmation' => ['date_seance' => now()->addDay()->format('Y-m-d'), 'heure_debut' => '08:00', 'heure_fin' => '10:00'],
                ]]]);
            }

            return Http::response(['success' => true, 'data' => []]);
        });
        $this->app->instance(HttpFactory::class, $factory);
    }

    private function compter(string $chemin): void
    {
        $this->appels[$chemin] = ($this->appels[$chemin] ?? 0) + 1;
    }
}
