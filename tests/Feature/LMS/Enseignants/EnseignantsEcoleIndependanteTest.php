<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Enseignants;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\Enseignant\InstitutionTeachersReader;
use App\Services\Enseignant\LocalInstitutionTeachersReader;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Monolog\Level;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * Lot 3b — les enseignants d'une école créée depuis le LMS : l'annuaire
 * (`/proxy/enseignants`) et la liste de l'administration (`/lms/enseignants`).
 *
 * Les deux routes appelaient KLASSCI : l'école créée depuis le LMS recevait le
 * 503 d'une configuration KLASSCI absente. La source locale lit les comptes
 * enseignants de l'école par la règle unique `TeacherDirectoryVisibility::
 * restrictLocal` (D120) : la gestion voit tout, un enseignant sa ligne, les
 * autres rien. Les ids sont LOCAUX, et aucun n'est rendu sous un nom `klassci_*`.
 */
final class EnseignantsEcoleIndependanteTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const ANNUAIRE = '/api/proxy/enseignants';

    private const LISTE = '/api/lms/enseignants';

    private const DETAILS = '/api/lms/enseignants?with_details=true';

    private const TEMOIN_SQL = 'temoin.sql.lot3b@exemple.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->app['config']->set('app.debug', false);
    }

    /** @return iterable<string, array{0: string}> */
    public static function gestion(): iterable
    {
        yield 'coordinateur' => ['coordinateur'];
        yield 'admin' => ['admin'];
        yield 'superAdmin' => ['superAdmin'];
    }

    // ───────────────────────── école créée depuis le LMS

    #[DataProvider('gestion')]
    public function test_la_gestion_lit_tous_les_enseignants_avec_les_ids_locaux(string $role): void
    {
        Http::fake();
        $ecole = $this->ecole();
        $awa = $this->enseignant($ecole, 'Awa Koné', '0700000001');
        $bakary = $this->enseignant($ecole, 'Bakary Diallo', null);
        $this->membre($ecole, 'etudiant');
        $this->enseignant($this->ecole(), 'Voisine', null);
        $lecteur = $this->membre($ecole, $role);

        $this->asTenant($lecteur)->getJson(self::ANNUAIRE)->assertOk()->assertExactJson([
            'success' => true,
            'data' => [$this->ligne($awa), $this->ligne($bakary)],
            'meta' => ['total' => 2, 'source' => 'lms_local'],
        ]);
        $this->asTenant($lecteur)->getJson(self::LISTE)->assertOk()->assertExactJson([
            'success' => true,
            'data' => [$this->ligne($awa), $this->ligne($bakary)],
            'meta' => ['total' => 2, 'source' => 'lms_local'],
        ]);
        Http::assertNothingSent();
    }

    /**
     * Les détails : les matières où il est désigné, avec leurs classes, et ses
     * classes. Les ids des classes sont ceux de `/proxy/classes`. Rien de ce
     * que le LMS ne tient (heures, taux, séances, filière, niveau) n'est
     * inventé : `null`, et aucun bloc `statistiques` (D120).
     */
    public function test_les_details_portent_les_designations_locales_sans_statistiques(): void
    {
        $ecole = $this->ecole();
        $awa = $this->enseignant($ecole, 'Awa Koné', null);
        $a = $this->classe($ecole, '6e A');
        $b = $this->classe($ecole, '6e B');
        $maths = $this->matiere($ecole, 'Maths');
        $svt = $this->matiere($ecole, 'SVT');
        $this->programme($a, $maths, $awa);
        $this->programme($b, $maths, $awa);
        $this->programme($b, $svt, $this->enseignant($ecole, 'Zoé Traoré', null));

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::DETAILS)->assertOk();

        self::assertSame($this->ligne($awa) + [
            'matieres' => [[
                'id' => $maths->id,
                'nom' => 'Maths',
                'code' => $maths->code,
                'classes' => [['id' => $a->id, 'nom' => '6e A'], ['id' => $b->id, 'nom' => '6e B']],
                'heures_prevues' => null,
                'heures_effectuees' => null,
                'taux_realisation' => null,
                'nb_seances_total' => null,
                'nb_seances_effectuees' => null,
            ]],
            'classes' => [
                ['id' => $a->id, 'nom' => '6e A', 'filiere' => null, 'niveau' => null],
                ['id' => $b->id, 'nom' => '6e B', 'filiere' => null, 'niveau' => null],
            ],
        ], $reponse->json('data.0'));
        self::assertDatabaseCount('lms_enseignants_cache', 0);
    }

    /**
     * Les tris que la spec affirme : les enseignants par nom, leurs matières
     * par libellé, les classes par libellé. Créés à l'envers de l'alphabet,
     * pour qu'un ordre d'insertion ne passe pas pour un tri.
     */
    public function test_les_enseignants_et_leurs_details_sont_tries(): void
    {
        $ecole = $this->ecole();
        $this->enseignant($ecole, 'Zoé Traoré', null);
        $awa = $this->enseignant($ecole, 'Awa Koné', null);
        $b = $this->classe($ecole, '6e B');
        $a = $this->classe($ecole, '6e A');
        $svt = $this->matiere($ecole, 'SVT');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($b, $svt, $awa);
        $this->programme($b, $maths, $awa);
        $this->programme($a, $maths, $awa);

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::DETAILS)->assertOk();

        self::assertSame(['Awa Koné', 'Zoé Traoré'], array_column($reponse->json('data'), 'nom'));
        self::assertSame(['Maths', 'SVT'], array_column($reponse->json('data.0.matieres'), 'nom'));
        self::assertSame(['6e A', '6e B'], array_column($reponse->json('data.0.matieres.0.classes'), 'nom'));
        self::assertSame(['6e A', '6e B'], array_column($reponse->json('data.0.classes'), 'nom'));
    }

    /**
     * Les classes d'un enseignant suivent l'ordre de la base, comme celles
     * d'une matière : la casse et les accents se classent selon la collation
     * (MySQL), pas octet par octet.
     */
    public function test_les_classes_d_un_enseignant_suivent_l_ordre_de_ses_matieres(): void
    {
        $ecole = $this->ecole();
        $awa = $this->enseignant($ecole, 'Awa Koné', null);
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($this->classe($ecole, '6e a'), $maths, $awa);
        $this->programme($this->classe($ecole, '6e B'), $maths, $awa);

        $ligne = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson(self::DETAILS)->assertOk()->json('data.0');

        self::assertSame(array_column($ligne['matieres'][0]['classes'], 'nom'), array_column($ligne['classes'], 'nom'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function routes(): iterable
    {
        yield 'annuaire' => [self::ANNUAIRE];
        yield 'liste' => [self::LISTE];
        yield 'liste, avec détails' => [self::DETAILS];
    }

    #[DataProvider('routes')]
    public function test_un_enseignant_ne_lit_que_sa_ligne(string $route): void
    {
        $ecole = $this->ecole();
        $lui = $this->enseignant($ecole, 'Awa Koné', null);
        $this->enseignant($ecole, 'Bakary Diallo', null);

        $this->asTenant($lui)->getJson($route)->assertOk()->assertJsonPath('data.*.id', [$lui->id]);
    }

    /**
     * Une école basculée garde les `klassci_id` de ses comptes. Ils ne sont
     * jamais rendus : l'id est celui du compte LOCAL, et aucune clé ne porte
     * un id KLASSCI (D132 Q3).
     */
    public function test_seul_l_id_local_est_rendu_meme_pour_un_compte_qui_garde_son_id_klassci(): void
    {
        $basculee = Institution::factory()->create([
            'mode' => InstitutionMode::Standalone,
            'klassci_api_url' => 'https://ecole.klassci.test/api/lms',
            'is_active' => true,
        ]);
        $awa = $this->enseignant($basculee, 'Awa Koné', null);
        $awa->forceFill(['klassci_id' => 9001, 'klassci_enseignant_id' => 501])->save();

        $ligne = $this->asTenant($this->membre($basculee, 'coordinateur'))->getJson(self::DETAILS)->assertOk()->json('data.0');

        self::assertSame($awa->id, $ligne['id']);
        self::assertSame([], array_values(array_filter(
            array_keys($ligne),
            static fn (string $cle): bool => str_starts_with($cle, 'klassci') || in_array($cle, ['teacher_id', 'user_id', 'enseignant_id'], true),
        )));
    }

    public function test_sans_tenant_la_source_reste_bornee_a_l_ecole(): void
    {
        // Par HTTP, le scope global borne aussi. Sans tenant résolu, il est
        // fail-open : le bornage explicite doit tenir seul.
        $ecole = $this->ecole();
        $autre = $this->ecole();
        $awa = $this->enseignant($ecole, 'Awa Koné', null);
        $this->enseignant($autre, 'Voisine', null);
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($this->classe($ecole, '6e A'), $maths, $awa);
        // Une désignation d'Awa dans une classe d'une AUTRE école : lue sans
        // borne, elle viendrait dans ses détails.
        DB::table('classe_matiere')->insert([
            'classe_id' => $this->classe($autre, 'Classe voisine')->id,
            'matiere_id' => $maths->id,
            'enseignant_id' => $awa->id,
            'institution_id' => $autre->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $coordinateur = $this->membre($ecole, 'coordinateur');
        app(TenantManager::class)->reset();
        $source = app(LocalInstitutionTeachersReader::class);

        self::assertSame([$awa->id], array_column($source->annuaire($coordinateur)['data'], 'id'));
        $details = $source->listeEnrichie($coordinateur, true)['data'];
        self::assertSame(['6e A'], array_column($details[0]['matieres'][0]['classes'], 'nom'));
        self::assertSame(['6e A'], array_column($details[0]['classes'], 'nom'));
    }

    // ───────────────────────── école créée depuis KLASSCI

    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        // Laravel garde le contrôleur sur l'objet Route : une source reçue par
        // le constructeur resterait celle de la première école servie.
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getEnseignants')->once()->andReturn(['success' => true, 'data' => [['id' => 77, 'nom' => 'KLASSCI']]]);
        });
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $locale = $this->ecole();
        $awa = $this->enseignant($locale, 'Awa Koné', null);

        $this->asTenant($this->membre($klassci, 'coordinateur'))->getJson(self::ANNUAIRE)->assertJsonPath('data.0.id', 77);
        $this->asTenant($this->membre($locale, 'coordinateur'))->getJson(self::ANNUAIRE)->assertJsonPath('data.0.id', $awa->id);
    }

    /**
     * Un refus de KLASSCI dont le message n'est pas un texte : la réponse porte
     * le texte fixe « Erreur KLASSCI », jamais la valeur brute (écart déclaré du
     * lot 3b, l'école créée depuis KLASSCI).
     */
    public function test_l_ecole_creee_depuis_klassci_rend_un_texte_fixe_pour_un_refus_au_message_non_textuel(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getEnseignantsEnrichis')->once()->andReturn(['success' => false, 'message' => ['code' => 'refus']]);
        });

        $this->asTenant($this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur'))
            ->getJson(self::LISTE)
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Erreur KLASSCI', 'data' => []]);
    }

    /**
     * Le cache des détails, réécrit par le lot : seuls les ids KLASSCI
     * numériques y entrent, entiers ou en chaîne (écart déclaré : un id non
     * numérique n'y entre pas).
     */
    public function test_l_ecole_creee_depuis_klassci_met_en_cache_les_ids_numeriques(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getEnseignantsEnrichis')->once()->with(true)->andReturn(['success' => true, 'data' => [
                ['id' => 12, 'nom' => 'Entier'],
                ['id' => '13', 'nom' => 'Chaîne numérique'],
                ['id' => 'abc', 'nom' => 'Non numérique'],
            ]]);
        });

        $this->asTenant($this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur'))
            ->getJson(self::DETAILS)
            ->assertOk()
            ->assertJsonCount(3, 'data');

        self::assertSame([12, 13], DB::table('lms_enseignants_cache')->orderBy('enseignant_id')->pluck('enseignant_id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    // ───────────────────────── la panne

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function pannes(): iterable
    {
        yield 'annuaire' => [self::ANNUAIRE, ['success' => false, 'message' => 'Service indisponible. Veuillez réessayer.']];
        yield 'liste' => [self::LISTE, ['success' => false, 'message' => 'Erreur lors du chargement des enseignants.', 'data' => []]];
    }

    /**
     * Une panne de base de la source locale est rapportée une fois, sans le
     * message ni les valeurs de la requête (#948), avec le corps d'aujourd'hui.
     *
     * @param  array<string, mixed>  $corps
     */
    #[DataProvider('pannes')]
    public function test_une_panne_de_base_est_rapportee_une_fois_sans_ses_valeurs(string $route, array $corps): void
    {
        $this->app->instance(LocalInstitutionTeachersReader::class, new class implements InstitutionTeachersReader
        {
            public function annuaire(User $lecteur): array
            {
                throw self::panne();
            }

            public function listeEnrichie(User $lecteur, bool $details): array
            {
                throw self::panne();
            }

            private static function panne(): QueryException
            {
                return new QueryException(
                    'sqlite',
                    'select * from users where email = ?',
                    ['temoin.sql.lot3b@exemple.invalid'],
                    new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
                );
            }
        });
        $this->captureWrittenLog();

        $this->asTenant($this->membre($this->ecole(), 'coordinateur'))
            ->getJson($route)
            ->assertStatus(500)
            ->assertExactJson($corps);

        $ecrits = $this->recordsAtLeast(Level::Error);
        self::assertCount(1, $ecrits, 'La panne doit être écrite une fois.');
        self::assertSame('select * from users where email = ?', $ecrits[0]->context['sql'] ?? null);
        $this->assertNoWitnessWritten(self::TEMOIN_SQL);
    }

    // ───────────────────────── harnais

    private function enseignant(Institution $ecole, string $nom, ?string $telephone): User
    {
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['name' => $nom, 'phone' => $telephone])->save();

        return $enseignant;
    }

    /**
     * La ligne attendue d'un enseignant local, sans détails.
     *
     * @return array<string, mixed>
     */
    private function ligne(User $enseignant): array
    {
        return [
            'id' => $enseignant->id,
            'nom' => $enseignant->name,
            'name' => $enseignant->name,
            'prenom' => '',
            'email' => $enseignant->email,
            'telephone' => $enseignant->phone,
            'matricule' => null,
            'specialization' => null,
            'status' => null,
        ];
    }
}
