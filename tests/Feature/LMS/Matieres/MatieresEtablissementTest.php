<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Matieres;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Matiere\InstitutionMatieresReader;
use App\Services\Matiere\KlassciInstitutionMatieresReader;
use App\Services\Matiere\LocalInstitutionMatieresReader;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * `GET /api/proxy/matieres` et `GET /api/admin/matieres` — les matières de
 * l'établissement (lot 3c, ADR-EI-01).
 *
 * Dans une école créée depuis le LMS, la première rendait 401 sans jeton et 503
 * avec, la seconde 401 : AdminMatieres ne se chargeait pas, les filtres par
 * matière de l'enseignant restaient vides, et le nombre de matières par classe
 * valait 0. Elles lisent désormais le catalogue local, en ids LOCAUX. Dans une
 * école créée depuis KLASSCI, elles relaient KLASSCI, inchangées.
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class MatieresEtablissementTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const LISTE = '/api/proxy/matieres';

    private const ADMIN = '/api/admin/matieres';

    private const TEMOIN_SQL = 'temoin.sql.lot3c@exemple.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->app['config']->set('app.debug', false);
    }

    // ───────────────────────── /proxy/matieres, école créée depuis le LMS

    public function test_la_gestion_lit_tout_le_catalogue_avec_les_classes_de_chaque_matiere(): void
    {
        $ecole = $this->ecole();
        // Créées dans le DÉSORDRE : les deux tris doivent être éprouvés.
        $sixieme = $this->classe($ecole, '6e A');
        $cinquieme = $this->classe($ecole, '5e B');
        $maths = $this->matiere($ecole, 'Maths');
        $francais = $this->matiere($ecole, 'Français');
        $musique = $this->matiere($ecole, 'Musique');
        $this->programme($sixieme, $maths, $this->membre($ecole, 'enseignant'));
        $this->programme($cinquieme, $maths, null);
        $this->programme($sixieme, $francais, null);

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::LISTE)->assertOk();

        $reponse->assertJsonPath('success', true);
        self::assertSame([
            $this->ligne($francais, [$sixieme]),
            $this->ligne($maths, [$cinquieme, $sixieme]),
            // Au catalogue, pas encore au programme : elle reste à la gestion.
            $this->ligne($musique, []),
        ], $reponse->json('data'));
    }

    /**
     * Ses matières, et pour chacune les classes où IL l'enseigne : ni la
     * matière d'un collègue dans sa classe, ni sa matière dans la classe d'un
     * collègue.
     */
    public function test_un_enseignant_ne_lit_que_les_matieres_ou_il_est_designe(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $collegue = $this->membre($ecole, 'enseignant');
        $sixieme = $this->classe($ecole, '6e A');
        $cinquieme = $this->classe($ecole, '5e B');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($sixieme, $maths, $moi);
        $this->programme($cinquieme, $maths, $collegue);
        $this->programme($sixieme, $this->matiere($ecole, 'Français'), $collegue);
        $this->matiere($ecole, 'Musique');

        $reponse = $this->asTenant($moi)->getJson(self::LISTE)->assertOk();

        self::assertSame([$this->ligne($maths, [$sixieme])], $reponse->json('data'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function lecteursHorsPersonnel(): iterable
    {
        yield 'apprenant' => ['etudiant'];
        // Un rôle non reconnu est traité comme un apprenant (#941).
        yield 'rôle non reconnu' => ['invite'];
    }

    /** Les matières de ses classes à inscription ACTIVE (D57), et ces classes-là. */
    #[DataProvider('lecteursHorsPersonnel')]
    public function test_hors_personnel_on_lit_les_matieres_de_ses_classes_actives(string $role): void
    {
        $ecole = $this->ecole();
        $lecteur = $this->membre($ecole, $role);
        $active = $this->classe($ecole, '6e A');
        $inactive = $this->classe($ecole, '5e B');
        $maths = $this->matiere($ecole, 'Maths');
        $francais = $this->matiere($ecole, 'Français');
        $this->programme($active, $maths, $this->membre($ecole, 'enseignant'));
        $this->programme($inactive, $maths, null);
        $this->programme($active, $francais, null);
        $this->programme($inactive, $this->matiere($ecole, 'Anglais'), null);
        $this->matiere($ecole, 'Musique');
        $this->inscrire($lecteur, $active, 'actif');
        $this->inscrire($lecteur, $inactive, 'inactif');

        $reponse = $this->asTenant($lecteur)->getJson(self::LISTE)->assertOk();

        self::assertSame([
            $this->ligne($francais, [$active]),
            $this->ligne($maths, [$active]),
        ], $reponse->json('data'));
    }

    /**
     * Le front compte les matières d'une classe en croisant `classes[].id` avec
     * les ids de `/proxy/classes` (`getClassMatiereCount`) : le compte doit
     * tomber sur `nb_matieres`, classe par classe.
     */
    public function test_les_classes_des_matieres_parlent_l_espace_de_la_liste_des_classes(): void
    {
        $ecole = $this->ecole();
        // Une classe d'abord, pour que les ids locaux des classes et des
        // matières ne se recouvrent pas par hasard.
        $this->classe($ecole, 'Classe vide');
        $sixieme = $this->classe($ecole, '6e A');
        $cinquieme = $this->classe($ecole, '5e B');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($sixieme, $maths, null);
        $this->programme($cinquieme, $maths, null);
        $this->programme($sixieme, $this->matiere($ecole, 'Français'), null);
        $coordinateur = $this->membre($ecole, 'coordinateur');

        $classes = (array) $this->asTenant($coordinateur)->getJson('/api/proxy/classes')->assertOk()->json('data');
        $matieres = (array) $this->asTenant($coordinateur)->getJson(self::LISTE)->assertOk()->json('data');

        self::assertCount(3, $classes);
        foreach ($classes as $classe) {
            $comptees = array_filter(
                $matieres,
                static fn (array $matiere): bool => in_array($classe['id'], array_column($matiere['classes'], 'id'), true),
            );
            self::assertSame($classe['nb_matieres'], count($comptees), 'classe '.$classe['libelle']);
        }
    }

    public function test_les_filtres_de_filiere_et_de_niveau_sont_sans_effet(): void
    {
        $ecole = $this->ecole();
        $maths = $this->matiere($ecole, 'Maths');

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::LISTE.'?filiere_id=3&niveau_id=4')
            ->assertOk();

        self::assertSame([$maths->id], array_column((array) $reponse->json('data'), 'id'));
    }

    public function test_aucun_appel_a_klassci_meme_pour_un_compte_porteur_d_un_jeton(): void
    {
        $ecole = $this->ecole();
        $this->matiere($ecole, 'Maths');
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getMatieres');
            $mock->shouldNotReceive('requestWithUserToken');
        });

        $this->asTenant($coordinateur)->getJson(self::LISTE)->assertOk()->assertJsonCount(1, 'data');
        $this->asTenant($coordinateur)->getJson(self::ADMIN)->assertOk()->assertJsonCount(1, 'data.matieres');
    }

    public function test_sans_tenant_les_deux_lectures_restent_bornees_a_l_ecole(): void
    {
        // Par HTTP, le scope global borne aussi. Sans tenant résolu, il est
        // fail-open : le bornage explicite doit tenir seul.
        $ecole = $this->ecole();
        $autre = $this->ecole();
        $maths = $this->matiere($ecole, 'Maths');
        $sixieme = $this->classe($ecole, '6e A');
        $this->programme($sixieme, $maths, null);
        $this->matiere($autre, 'Matière voisine');
        DB::table('classe_matiere')->insert([
            'classe_id' => $this->classe($autre, 'Classe voisine')->id,
            'matiere_id' => $maths->id,
            'institution_id' => $autre->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->lecon($sixieme, $maths, $this->membre($ecole, 'enseignant'), 'published');
        Lesson::factory()->published()->create(['institution_id' => $autre->id, 'matiere_id' => $maths->id]);
        $coordinateur = $this->membre($ecole, 'coordinateur');
        app(TenantManager::class)->reset();
        $lecteur = app(LocalInstitutionMatieresReader::class);

        self::assertSame([$this->ligne($maths, [$sixieme])], $lecteur->list($coordinateur, [])['data']);
        $admin = $lecteur->listForAdmin($coordinateur)['matieres'];
        self::assertSame([$maths->id], array_column($admin, 'id'));
        self::assertSame(1, $admin[0]['nb_lecons']);
    }

    // ───────────────────────── /admin/matieres, école créée depuis le LMS

    public function test_l_administration_lit_tout_le_catalogue_et_compte_ses_lecons(): void
    {
        $ecole = $this->ecole();
        $sixieme = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $musique = $this->matiere($ecole, 'Musique');
        $this->programme($sixieme, $maths, null);
        // Tous statuts, tous auteurs : ce que la page de la matière montre à
        // la gestion.
        $this->lecon($sixieme, $maths, $this->membre($ecole, 'enseignant'), 'published');
        $this->lecon($sixieme, $maths, $this->membre($ecole, 'enseignant'), 'draft');
        $this->lecon($sixieme, $maths, $this->membre($ecole, 'enseignant'), 'archived');

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::ADMIN)->assertOk();

        $reponse->assertJsonPath('success', true)->assertJsonPath('message', '2 matière(s) récupérée(s)');
        self::assertSame([
            $this->ligneAdmin($maths, 3),
            $this->ligneAdmin($musique, 0),
        ], $reponse->json('data.matieres'));
        // Aucune heure n'est connue : un total de 0 se lirait comme une mesure.
        self::assertSame(['total' => 2, 'total_heures' => null, 'total_seances' => null], $reponse->json('data.statistiques'));
    }

    public function test_un_catalogue_vide_est_dit_vide(): void
    {
        $ecole = $this->ecole();

        $this->asTenant($this->membre($ecole, 'admin'))
            ->getJson(self::ADMIN)
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Aucune matière trouvée',
                'data' => ['matieres' => [], 'statistiques' => ['total' => 0, 'total_heures' => 0, 'total_seances' => null]],
            ]);
    }

    public function test_un_code_absent_est_rendu_comme_dans_l_ecole_creee_depuis_klassci(): void
    {
        $ecole = $this->ecole();
        $this->matiere($ecole, 'Maths')->forceFill(['code' => null])->save();

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::ADMIN)
            ->assertOk()
            ->assertJsonPath('data.matieres.0.code', 'N/A');
    }

    // ───────────────────────── école créée depuis KLASSCI

    public function test_l_ecole_creee_depuis_klassci_recoit_ce_que_rend_klassci_avec_le_jeton_personnel(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $rendu = ['success' => true, 'data' => [['id' => 12, 'nom' => 'Comptabilité']]];
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($rendu): void {
            $mock->shouldReceive('getMatieres')->once()->with('jeton-personnel', ['filiere_id' => '3'])->andReturn($rendu);
        });
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-personnel'])->save();

        $this->asTenant($coordinateur)
            ->getJson(self::LISTE.'?filiere_id=3')
            ->assertOk()
            ->assertExactJson($rendu);
    }

    /** @return iterable<string, array{0: string}> */
    public static function routes(): iterable
    {
        yield '/proxy/matieres' => [self::LISTE];
        yield '/admin/matieres' => [self::ADMIN];
    }

    /** Le jeton, vérifié désormais par la source KLASSCI : la même réponse qu'avant. */
    #[DataProvider('routes')]
    public function test_sans_jeton_l_ecole_creee_depuis_klassci_recoit_le_meme_refus(string $route): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getMatieres');
            $mock->shouldNotReceive('requestWithUserToken');
        });

        $this->asTenant($this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur'))
            ->getJson($route)
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Aucun accès KLASSCI n\'est enregistré pour ce compte : ces données, qui proviennent de KLASSCI, ne peuvent pas être affichées.',
                'reason' => 'klassci_token_missing',
            ]);
    }

    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        // Laravel garde le contrôleur sur l'objet Route : une source reçue par
        // le constructeur resterait celle de la première école servie.
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getMatieres')->once()->andReturn(['success' => true, 'data' => [['id' => 12]]]);
            $mock->shouldReceive('requestWithUserToken')->once()->andReturn(['success' => true, 'data' => [['id' => 13]]]);
        });
        $porteur = $this->membre($klassci, 'coordinateur');
        $porteur->forceFill(['klassci_token' => 'jeton-personnel'])->save();
        $locale = $this->ecole();
        $maths = $this->matiere($locale, 'Maths');
        $local = $this->membre($locale, 'coordinateur');

        $this->asTenant($porteur)->getJson(self::LISTE)->assertJsonPath('data.0.id', 12);
        $this->asTenant($local)->getJson(self::LISTE)->assertJsonPath('data.0.id', $maths->id);
        $this->asTenant($porteur)->getJson(self::ADMIN)->assertJsonPath('data.matieres.0.id', 13);
        $this->asTenant($local)->getJson(self::ADMIN)->assertJsonPath('data.matieres.0.id', $maths->id);
    }

    // ───────────────────────── une panne de base

    /** @return iterable<string, array{0: InstitutionMode, 1: class-string<InstitutionMatieresReader>, 2: string, 3: array<string, mixed>}> */
    public static function pannes(): iterable
    {
        $proxy = ['success' => false, 'message' => 'Service indisponible. Veuillez réessayer.'];
        $admin = ['success' => false, 'message' => 'Erreur lors de la récupération des matières', 'error' => 'Une erreur est survenue.'];

        yield 'liste, école créée depuis le LMS' => [InstitutionMode::Standalone, LocalInstitutionMatieresReader::class, self::LISTE, $proxy];
        yield 'liste, école créée depuis KLASSCI' => [InstitutionMode::Klassci, KlassciInstitutionMatieresReader::class, self::LISTE, $proxy];
        yield 'administration, école créée depuis le LMS' => [InstitutionMode::Standalone, LocalInstitutionMatieresReader::class, self::ADMIN, $admin];
        yield 'administration, école créée depuis KLASSCI' => [InstitutionMode::Klassci, KlassciInstitutionMatieresReader::class, self::ADMIN, $admin];
    }

    /**
     * Une panne de base est rapportée une fois, sans le message ni les valeurs
     * de la requête (#948). L'administration écrivait le message de
     * l'exception, valeurs liées comprises, dans le journal.
     *
     * @param  class-string<InstitutionMatieresReader>  $source
     * @param  array<string, mixed>  $corps
     */
    #[DataProvider('pannes')]
    public function test_une_panne_de_base_est_rapportee_une_fois_sans_ses_valeurs(InstitutionMode $mode, string $source, string $route, array $corps): void
    {
        $this->app->instance($source, new class implements InstitutionMatieresReader
        {
            public function list(User $user, array $filtres): array
            {
                throw self::panne();
            }

            public function listForAdmin(User $user): array
            {
                throw self::panne();
            }

            private static function panne(): QueryException
            {
                return new QueryException(
                    'sqlite',
                    'select * from matieres where email = ?',
                    ['temoin.sql.lot3c@exemple.invalid'],
                    new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
                );
            }
        });
        $this->captureWrittenLog();

        $this->asTenant($this->membre($this->ecole($mode), 'coordinateur'))
            ->getJson($route)
            ->assertStatus(500)
            ->assertExactJson($corps);

        $ecrits = $this->recordsAtLeast(Level::Error);
        self::assertCount(1, $ecrits, 'La panne doit être écrite une fois.');
        self::assertSame('select * from matieres where email = ?', $ecrits[0]->context['sql'] ?? null);
        $this->assertNoWitnessWritten(self::TEMOIN_SQL);
    }

    // ───────────────────────── harnais

    /**
     * La ligne attendue d'une matière de `/proxy/matieres`.
     *
     * @param  list<Classe>  $classes
     * @return array<string, mixed>
     */
    private function ligne(Matiere $matiere, array $classes): array
    {
        return [
            'id' => $matiere->id,
            'nom' => $matiere->libelle,
            'code' => $matiere->code,
            'description' => $matiere->description,
            'coefficient' => $matiere->coefficient,
            'classes' => array_map(static fn (Classe $classe): array => ['id' => $classe->id, 'nom' => $classe->libelle], $classes),
        ];
    }

    /**
     * La ligne attendue d'une matière de `/admin/matieres`.
     *
     * @return array<string, mixed>
     */
    private function ligneAdmin(Matiere $matiere, int $lecons): array
    {
        return [
            'id' => $matiere->id,
            'nom' => $matiere->libelle,
            'code' => $matiere->code,
            'description' => $matiere->description,
            'coefficient' => $matiere->coefficient,
            'couleur' => '#6366f1',
            'heures_total' => null,
            'nb_evaluations' => 0,
            'nb_seances_programmees' => null,
            'nb_lecons' => $lecons,
            'combinaisons' => [],
        ];
    }
}
