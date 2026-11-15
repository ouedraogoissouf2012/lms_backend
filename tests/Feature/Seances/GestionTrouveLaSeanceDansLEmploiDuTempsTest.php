<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Enums\InstitutionMode;
use App\Jobs\DispatchVisioNotification;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Temps 1 de D610 : la gestion (coordinateur, admin) retrouve une séance KLASSCI
 * dans l'emploi du temps, et non plus dans `matieres/{id}.data.seances_programmees`,
 * une clé que KLASSCI laisse toujours vide (mesure de production :
 * `EmploiTempsSeanceLocator`).
 *
 * - Le DÉTAIL d'une séance, pour la gestion, tombait dans le repli local, ou en 404
 *   quand aucune ligne locale n'existait.
 * - Le premier BASCULEMENT visio d'une séance sans ligne locale ne la trouvait
 *   jamais : sa classe restait inconnue, et aucun élève n'était averti.
 *
 * La liste des matières reste celle d'aujourd'hui pour la gestion, le catalogue
 * `matieres` : aucun élargissement. Une séance d'une matière hors du catalogue
 * reste introuvable. Un seul appel `emploi-temps`, aucun `matieres/{id}` (#744).
 *
 * KLASSCI est simulé tel qu'il répond (clés de la capture de développement).
 * Seule la frontière réseau est feinte, une closure par requête ; vrai jeton.
 */
final class GestionTrouveLaSeanceDansLEmploiDuTempsTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const TENANT_URL = 'https://klassci.ecole.test';

    private const SEANCE = 349;

    private const MATIERE = 3;

    private const CLASSE = 4;

    private const CLASSE_NOM = 'BTS Genie Civil';

    private Institution $ecole;

    /** @var array<string, int> appels reçus, par chemin KLASSCI */
    private array $appels = [];

    private int $matiereDeLaSeance = self::MATIERE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Queue::fake();
        $this->ecole = Institution::factory()->create([
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => self::TENANT_URL,
        ]);
        $this->klassciTelQuIlRepond();
    }

    /** @return array<string, array{0: string}> */
    public static function gestion(): array
    {
        return ['coordinateur' => ['coordinateur'], 'superAdmin' => ['superAdmin'], 'admin' => ['admin']];
    }

    #[DataProvider('gestion')]
    public function test_la_gestion_lit_le_detail_d_une_seance_de_l_emploi_du_temps(string $role): void
    {
        $reponse = $this->asTenant($this->membre($role))->getJson('/api/lms/seances/'.self::SEANCE.'/details');

        $reponse->assertOk()
            ->assertJsonPath('data.seance.id', self::SEANCE)
            ->assertJsonPath('data.seance.classe.nom', self::CLASSE_NOM)
            ->assertJsonPath('data.seance.matiere.id', self::MATIERE);
        self::assertSame(1, $this->appels['emploi-temps'] ?? 0, 'Un seul appel à l emploi du temps.');
        self::assertSame(0, $this->appels['matieres/{id}'] ?? 0, 'Aucune lecture de la clé morte, matière par matière.');
    }

    public function test_une_seance_d_une_matiere_hors_du_catalogue_reste_introuvable(): void
    {
        $this->matiereDeLaSeance = 99;

        $this->asTenant($this->membre('coordinateur'))
            ->getJson('/api/lms/seances/'.self::SEANCE.'/details')
            ->assertNotFound();
    }

    /** @return array<string, array{0: string}> */
    public static function basculeurs(): array
    {
        return ['coordinateur' => ['coordinateur'], 'superAdmin' => ['superAdmin']];
    }

    #[DataProvider('basculeurs')]
    public function test_le_premier_basculement_trouve_la_classe_et_avertit_les_eleves(string $role): void
    {
        $this->asTenant($this->membre($role))
            ->postJson('/api/lms/seances/'.self::SEANCE.'/toggle-visio', ['enabled' => true])
            ->assertOk();

        $ligne = Seance::query()->where('klassci_seance_id', self::SEANCE)->first();
        self::assertNotNull($ligne);
        self::assertSame(
            [self::CLASSE, self::MATIERE, 'Anglais', 'Awa Enseignante'],
            [$ligne->klassci_classe_id, $ligne->klassci_matiere_id, $ligne->matiere_nom, $ligne->enseignant_nom],
        );
        // Ni `enseignant.id` ni `enseignant.teacher_id` d'emploi-temps ne sont établis
        // comme l'identifiant que la synchronisation écrit : rien n'est deviné.
        self::assertNull($ligne->klassci_enseignant_id);

        $jobs = Queue::pushed(DispatchVisioNotification::class);
        self::assertCount(1, $jobs, 'Les élèves de la classe sont avertis.');
        $donnees = (new ReflectionProperty(DispatchVisioNotification::class, 'seanceData'))->getValue($jobs->first());
        self::assertIsArray($donnees);
        self::assertSame(self::CLASSE, $donnees['klassci_classe_id'] ?? null);
        self::assertSame(0, $this->appels['matieres/{id}'] ?? 0);
    }

    private function membre(string $role): User
    {
        return User::factory()->for($this->ecole)->create([
            'role' => $role,
            'klassci_token' => 'jeton-gestion',
            'klassci_tenant_url' => self::TENANT_URL,
            'last_klassci_sync' => now(),
        ]);
    }

    /**
     * Les formes de la capture de développement (clés seules), valeurs fictives :
     * le catalogue `matieres`, `emploi-temps`, et `matieres/{id}` dont
     * `seances_programmees` est vide, comme en production.
     */
    private function klassciTelQuIlRepond(): void
    {
        $factory = new HttpFactory;
        $factory->fake(function (ClientRequest $requete): PromiseInterface {
            $chemin = (string) parse_url($requete->url(), PHP_URL_PATH);

            if (preg_match('#/matieres/\d+$#', $chemin) === 1) {
                $this->compter('matieres/{id}');

                return Http::response(['success' => true, 'data' => [
                    'matiere' => ['id' => self::MATIERE, 'nom' => 'Anglais', 'code' => 'ANG'],
                    'seances_programmees' => [],
                ]]);
            }
            if (str_ends_with($chemin, '/matieres')) {
                $this->compter('matieres');

                return Http::response(['success' => true, 'data' => [
                    ['id' => self::MATIERE, 'nom' => 'Anglais', 'code' => 'ANG'],
                ]]);
            }
            if (str_ends_with($chemin, '/emploi-temps')) {
                $this->compter('emploi-temps');

                return Http::response(['success' => true, 'data' => [$this->seanceKlassci()]]);
            }

            return Http::response(['success' => true, 'data' => []]);
        });

        $this->app->instance(HttpFactory::class, $factory);
    }

    /** @return array<string, mixed> */
    private function seanceKlassci(): array
    {
        return [
            'id' => self::SEANCE,
            'titre' => 'Anglais',
            'matiere' => ['id' => $this->matiereDeLaSeance, 'nom' => 'Anglais', 'code' => 'ANG', 'couleur' => '#00f'],
            'classe' => ['id' => self::CLASSE, 'nom' => self::CLASSE_NOM],
            'enseignant' => ['id' => 77, 'teacher_id' => 88, 'nom' => 'Awa Enseignante'],
            'programmation' => [
                'date_cours' => now()->addDay()->format('Y-m-d'),
                'date_seance' => now()->addDay()->format('Y-m-d'),
                'heure_debut' => '08:00',
                'heure_fin' => '10:00',
                'duree_minutes' => 120,
            ],
            'salle' => ['id' => null, 'nom' => 'A1', 'capacite' => null],
            'type_cours' => 'CM',
            'statut' => 'programme',
            'lms_integration' => ['can_start_visio' => true, 'visio_url' => null, 'course_materials_count' => 0],
        ];
    }

    private function compter(string $chemin): void
    {
        $this->appels[$chemin] = ($this->appels[$chemin] ?? 0) + 1;
    }
}
