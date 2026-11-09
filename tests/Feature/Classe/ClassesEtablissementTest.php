<?php

declare(strict_types=1);

namespace Tests\Feature\Classe;

use App\Enums\InstitutionMode;
use App\Enums\TrainingSessionStatus;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Classe\InstitutionClassesReader;
use App\Services\Classe\KlassciInstitutionClassesReader;
use App\Services\Classe\LocalInstitutionClassesReader;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Monolog\Level;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * `GET /api/proxy/classes` — les classes de l'établissement, pour les écrans de
 * gestion (lot 3a, ADR-EI-01).
 *
 * Dans une école créée depuis le LMS, la route rendait 503 sans même appeler
 * KLASSCI : la liste d'administration, le Hub, le tableau de bord et les filtres
 * de classe restaient vides. Elle lit désormais les classes locales. Dans une
 * école créée depuis KLASSCI, elle relaie KLASSCI, inchangée.
 *
 * Dans une école basculée, la liste donne l'id LOCAL, celui qu'attendent les
 * portes de la fiche (#1001) : c'est ce qui ferme la dette de la bascule.
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class ClassesEtablissementTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const LISTE = '/api/proxy/classes';

    private const TEMOIN_SQL = 'temoin.sql.lot3a@exemple.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->app['config']->set('app.debug', false);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ───────────────────────── école créée depuis le LMS

    public function test_la_gestion_lit_toutes_les_classes_de_l_ecole_avec_les_ids_locaux(): void
    {
        $ecole = $this->ecole();
        // Créées dans le DÉSORDRE : le tri par libellé doit être éprouvé.
        $sixieme = $this->classe($ecole, '6e A');
        $cinquieme = $this->classe($ecole, '5e B');
        $this->programme($sixieme, $this->matiere($ecole, 'Maths'), $this->membre($ecole, 'enseignant'));
        $this->programme($sixieme, $this->matiere($ecole, 'Français'), null);
        $this->inscrire($this->membre($ecole, 'etudiant'), $sixieme, 'actif');
        $this->inscrire($this->membre($ecole, 'etudiant'), $sixieme, 'actif');
        $this->inscrire($this->membre($ecole, 'etudiant'), $sixieme, 'inactif');

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::LISTE)->assertOk();

        $reponse->assertJsonPath('success', true);
        self::assertSame([
            $this->ligne($cinquieme, '5e B', null, 0, 0),
            $this->ligne($sixieme, '6e A', null, 2, 2),
        ], $reponse->json('data'));
    }

    /** @return iterable<string, array{0: TrainingSessionStatus|null, 1: string, 2: bool|null}> */
    public static function periodes(): iterable
    {
        yield 'publiée, en cours' => [TrainingSessionStatus::Publiee, 'en_cours', true];
        yield 'publiée, inscriptions ouvertes' => [TrainingSessionStatus::Publiee, 'inscriptions_ouvertes', true];
        yield 'publiée, à venir' => [TrainingSessionStatus::Publiee, 'a_venir', false];
        yield 'publiée, terminée' => [TrainingSessionStatus::Publiee, 'terminee', false];
        yield 'brouillon, en cours' => [TrainingSessionStatus::Brouillon, 'en_cours', false];
        yield 'annulée, en cours' => [TrainingSessionStatus::Annulee, 'en_cours', false];
        yield 'sans Période' => [null, 'en_cours', null];
    }

    #[DataProvider('periodes')]
    public function test_is_active_suit_la_periode_de_la_classe(?TrainingSessionStatus $statut, string $phase, ?bool $attendu): void
    {
        Carbon::setTestNow('2026-11-09');
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        if ($statut !== null) {
            $classe->forceFill(['training_session_id' => $this->periode($ecole, $statut, $phase)->id])->save();
        }

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::LISTE)
            ->assertOk()
            ->assertJsonPath('data.0.is_active', $attendu);
    }

    public function test_un_enseignant_ne_lit_que_ses_classes(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $sienne = $this->classe($ecole, '6e A');
        $this->programme($sienne, $this->matiere($ecole, 'Maths'), $moi);
        $this->programme($this->classe($ecole, '5e B'), $this->matiere($ecole, 'Français'), $this->membre($ecole, 'enseignant'));

        $reponse = $this->asTenant($moi)->getJson(self::LISTE)->assertOk();

        self::assertSame([$sienne->id], array_column((array) $reponse->json('data'), 'id'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function lecteursHorsPersonnel(): iterable
    {
        yield 'apprenant' => ['etudiant'];
        // Un rôle non reconnu est traité comme un apprenant (#941).
        yield 'rôle non reconnu' => ['invite'];
    }

    /**
     * Ses classes à inscription ACTIVE (D57), avec leur effectif, jamais une
     * liste d'élèves (GHSA-gg7j).
     */
    #[DataProvider('lecteursHorsPersonnel')]
    public function test_hors_personnel_on_lit_ses_classes_actives_sans_aucune_liste_d_eleves(string $role): void
    {
        $ecole = $this->ecole();
        $lecteur = $this->membre($ecole, $role);
        $active = $this->classe($ecole, '6e A');
        $inactive = $this->classe($ecole, '5e B');
        $this->classe($ecole, '4e C');
        $this->inscrire($lecteur, $active, 'actif');
        $this->inscrire($this->membre($ecole, 'etudiant'), $active, 'actif');
        $this->inscrire($lecteur, $inactive, 'inactif');

        $reponse = $this->asTenant($lecteur)->getJson(self::LISTE)->assertOk();

        $lignes = (array) $reponse->json('data');
        self::assertSame([$active->id], array_column($lignes, 'id'));
        self::assertSame(2, $lignes[0]['places_occupees']);
        foreach ($lignes as $ligne) {
            self::assertSame(array_keys($this->ligne($active, '', null, 0, 0)), array_keys($ligne), 'clé non décrite');
        }
        self::assertStringNotContainsString('etudiants', (string) $reponse->getContent());
    }

    public function test_les_filtres_de_filiere_et_de_niveau_sont_sans_effet(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::LISTE.'?filiere_id=3&niveau_id=4&annee_id=5')
            ->assertOk();

        self::assertSame([$classe->id], array_column((array) $reponse->json('data'), 'id'));
    }

    public function test_aucun_appel_a_klassci_meme_pour_un_compte_porteur_d_un_jeton(): void
    {
        $ecole = $this->ecole();
        $this->classe($ecole, '6e A');
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getClasses');
        });

        $this->asTenant($coordinateur)->getJson(self::LISTE)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_sans_tenant_la_liste_reste_bornee_a_l_ecole(): void
    {
        // Par HTTP, le scope global borne aussi. Sans tenant résolu, il est
        // fail-open : le bornage explicite doit tenir seul.
        $ecole = $this->ecole();
        $autre = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $this->classe($autre, 'Classe voisine');
        $this->inscrire($this->membre($autre, 'etudiant'), $classe, 'actif');
        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->id,
            'matiere_id' => $this->matiere($autre, 'Matière voisine')->id,
            'institution_id' => $ecole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $coordinateur = $this->membre($ecole, 'coordinateur');
        app(TenantManager::class)->reset();

        $reponse = app(LocalInstitutionClassesReader::class)->list($coordinateur, []);

        self::assertSame([$this->ligne($classe, '6e A', null, 0, 0)], $reponse['data']);
    }

    // ───────────────────────── école créée depuis KLASSCI

    public function test_l_ecole_creee_depuis_klassci_recoit_ce_que_rend_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $rendu = ['success' => true, 'data' => [['id' => 12, 'name' => 'B2 COM', 'places_occupees' => 18]]];
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($rendu): void {
            $mock->shouldReceive('getClasses')->once()->with(['filiere_id' => '3'])->andReturn($rendu);
        });

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::LISTE.'?filiere_id=3')
            ->assertOk()
            ->assertExactJson($rendu);
    }

    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        // Laravel garde le contrôleur sur l'objet Route : une source reçue par
        // le constructeur resterait celle de la première école servie.
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getClasses')->once()->andReturn(['success' => true, 'data' => [['id' => 12]]]);
        });
        $locale = $this->ecole();
        $classe = $this->classe($locale, '6e A');

        $this->asTenant($this->membre($klassci, 'coordinateur'))->getJson(self::LISTE)->assertJsonPath('data.0.id', 12);
        $this->asTenant($this->membre($locale, 'coordinateur'))->getJson(self::LISTE)->assertJsonPath('data.0.id', $classe->id);
    }

    // ───────────────────────── la bascule (dette D93 de #1001)

    /**
     * Une école créée depuis KLASSCI, basculée en mode autonome : ses classes
     * gardent leur `klassci_id`. La liste d'administration et le référentiel de
     * « Mes classes » donnent désormais l'id LOCAL, celui que lisent les portes
     * de la fiche : un id de la liste ouvre LA même classe.
     */
    public function test_dans_une_ecole_basculee_la_liste_et_la_fiche_parlent_le_meme_espace(): void
    {
        $basculee = Institution::factory()->create([
            'mode' => InstitutionMode::Standalone,
            'klassci_api_url' => 'https://ecole.klassci.test/api/lms',
            'is_active' => true,
        ]);
        // Un id KLASSCI qui collisionne avec l'id local d'une AUTRE classe.
        $premiere = Classe::factory()->create(['institution_id' => $basculee->id, 'libelle' => 'B1 COM', 'klassci_id' => 900001]);
        $seconde = Classe::factory()->create(['institution_id' => $basculee->id, 'libelle' => 'B2 COM', 'klassci_id' => $premiere->id]);
        $enseignant = $this->membre($basculee, 'enseignant');
        $this->programme($seconde, $this->matiere($basculee, 'Anglais'), $enseignant);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getClasses');
        });
        $coordinateur = $this->membre($basculee, 'coordinateur');

        $liste = (array) $this->asTenant($coordinateur)->getJson(self::LISTE)->assertOk()->json('data');
        foreach ($liste as $ligne) {
            $this->asTenant($coordinateur)->getJson('/api/lms/classes/'.$ligne['id'])
                ->assertOk()
                ->assertJsonPath('data.classe.id', $ligne['id'])
                ->assertJsonPath('data.classe.name', $ligne['libelle']);
        }

        $referentiel = array_column((array) $this->asTenant($enseignant)->getJson(self::LISTE)->json('data'), 'id');
        $mesClasses = array_column((array) $this->asTenant($enseignant)->getJson('/api/lms/teacher/classes')->json('data'), 'id');
        self::assertSame([$seconde->id], $mesClasses);
        self::assertSame($mesClasses, $referentiel, 'le référentiel fusionné par id parle un autre espace');
    }

    // ───────────────────────── une panne de base

    /** @return iterable<string, array{0: InstitutionMode, 1: class-string<InstitutionClassesReader>}> */
    public static function sources(): iterable
    {
        yield 'école créée depuis le LMS' => [InstitutionMode::Standalone, LocalInstitutionClassesReader::class];
        yield 'école créée depuis KLASSCI' => [InstitutionMode::Klassci, KlassciInstitutionClassesReader::class];
    }

    /**
     * Le 500 de la route était muet : la branche « défaillance interne » du rendu
     * proxy ne rapportait rien. La panne est désormais rapportée une fois, sans
     * le message ni les valeurs de la requête (#948).
     *
     * @param  class-string<InstitutionClassesReader>  $source
     */
    #[DataProvider('sources')]
    public function test_une_panne_de_base_est_rapportee_une_fois_sans_ses_valeurs(InstitutionMode $mode, string $source): void
    {
        $this->app->instance($source, new class implements InstitutionClassesReader
        {
            public function list(User $user, array $filtres): array
            {
                throw new QueryException(
                    'sqlite',
                    'select * from classes where email = ?',
                    ['temoin.sql.lot3a@exemple.invalid'],
                    new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
                );
            }
        });
        $this->captureWrittenLog();

        $this->asTenant($this->membre($this->ecole($mode), 'coordinateur'))
            ->getJson(self::LISTE)
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Service indisponible. Veuillez réessayer.']);

        $ecrits = $this->recordsAtLeast(Level::Error);
        self::assertCount(1, $ecrits, 'La panne doit être écrite une fois.');
        self::assertSame('select * from classes where email = ?', $ecrits[0]->context['sql'] ?? null);
        $this->assertNoWitnessWritten(self::TEMOIN_SQL);
    }

    // ───────────────────────── harnais

    /**
     * La ligne attendue d'une classe locale.
     *
     * @return array<string, mixed>
     */
    private function ligne(Classe $classe, string $libelle, ?bool $active, int $occupees, int $matieres): array
    {
        return [
            'id' => $classe->id,
            'name' => $libelle,
            'libelle' => $libelle,
            'code' => $classe->code,
            'filiere' => null,
            'niveau' => null,
            'is_active' => $active,
            'places_occupees' => $occupees,
            'places_totales' => null,
            'nb_matieres' => $matieres,
        ];
    }

    /** Une Période de l'école, placée dans la phase demandée au 2026-11-09. */
    private function periode(Institution $ecole, TrainingSessionStatus $statut, string $phase): TrainingSession
    {
        $dates = [
            'a_venir' => ['2026-12-01', '2026-12-15', '2027-01-05', '2027-04-30', '2027-05-15'],
            'inscriptions_ouvertes' => ['2026-11-01', '2026-11-30', '2026-12-01', '2027-03-31', '2027-04-15'],
            'en_cours' => ['2026-10-01', '2026-10-20', '2026-11-01', '2027-02-28', '2027-03-15'],
            'terminee' => ['2026-06-01', '2026-06-15', '2026-07-01', '2026-10-31', '2026-11-15'],
        ][$phase];

        return TrainingSession::factory()->create([
            'institution_id' => $ecole->id,
            'program_id' => Program::factory()->create(['institution_id' => $ecole->id])->id,
            'status' => $statut,
            'enrollment_opens_at' => $dates[0],
            'enrollment_closes_at' => $dates[1],
            'starts_on' => $dates[2],
            'ends_on' => $dates[3],
            'certificate_available_at' => $dates[4],
        ]);
    }
}
