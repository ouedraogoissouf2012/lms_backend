<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\LocalSeanceCreator;
use App\Services\Seances\LocalSeancesListReader;
use App\Services\Seances\SeancesListReader;
use App\Services\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
 * Lot 3d — les séances d'une école créée depuis le LMS : les séances à venir
 * (écrans Séances et Visio, calendrier de la gestion), l'emploi du temps de
 * l'enseignant et celui de l'élève.
 *
 * Les trois listes exigeaient un jeton KLASSCI avant tout le reste : l'école
 * créée depuis le LMS recevait partout le 401 « Aucun accès KLASSCI ». Et le
 * chemin du gestionnaire, pourtant local, écartait les séances locales.
 *
 * La grille (D119) : la gestion lit toutes les séances de l'école ; un
 * enseignant, les siennes (`created_by`, comme l'historique) ; un élève, et
 * tout rôle non reconnu, celles de ses classes à inscription active (D57),
 * moins celles qu'il a masquées.
 */
final class SeancesEcoleIndependanteTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const A_VENIR = '/api/lms/seances/upcoming';

    private const ENSEIGNEMENT = '/api/lms/seances/my-teaching';

    private const MES_CLASSES = '/api/lms/seances/my-classes';

    private const TEMOIN_SQL = 'temoin.sql.lot3d@exemple.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->app['config']->set('app.debug', false);
        Carbon::setTestNow('2026-11-10 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ───────────────────────── la gestion

    /** @return iterable<string, array{0: string}> */
    public static function gestion(): iterable
    {
        yield 'coordinateur' => ['coordinateur'];
        yield 'admin' => ['admin'];
        yield 'superAdmin' => ['superAdmin'];
    }

    /**
     * La ligne entière, une fois : les ids sont LOCAUX (ceux de
     * `/lms/seances/{id}`, de `/proxy/classes` et du compte auteur), une séance
     * locale n'a qu'un nom de matière, et sa fin est supposée (2 h).
     */
    #[DataProvider('gestion')]
    public function test_la_gestion_lit_les_seances_locales_avec_leurs_ids_locaux(string $role): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $auteur = $this->membre($ecole, 'enseignant');
        $seance = $this->seance($classe, $auteur, '2026-11-12 09:30:00');
        // Deux valeurs distinctes : une inversion des champs visio se verrait.
        $seance->forceFill(['visio_enabled' => true, 'visio_active' => false])->save();

        $reponse = $this->asTenant($this->membre($ecole, $role))
            ->getJson(self::A_VENIR)
            ->assertOk();

        self::assertSame([$this->ligne($seance, $classe, $auteur)], $reponse->json('data'));
        self::assertSame([
            'total_seances' => 1,
            'date_debut' => '2026-11-10',
            'date_fin' => '2026-12-10',
            'filtres' => ['teacher_id' => null, 'classe_id' => null],
        ], $reponse->json('meta'));
    }

    public function test_la_gestion_lit_toutes_les_seances_de_la_fenetre_et_seulement_elles(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $un = $this->membre($ecole, 'enseignant');
        $deux = $this->membre($ecole, 'enseignant');
        $demain = $this->seance($classe, $un, '2026-11-11 08:00:00');
        $sansClasse = $this->seance(null, $deux, '2026-11-20 10:00:00');
        $this->seance($classe, $un, '2026-11-09 08:00:00');                          // passée
        $this->seance($classe, $un, '2026-12-11 08:00:00');                          // hors des 30 jours
        $this->seance($classe, $un, '2026-11-15 08:00:00')->update(['is_active' => false]); // archivée
        $voisine = $this->ecole();
        $this->seance($this->classe($voisine, 'Voisine'), $this->membre($voisine, 'enseignant'), '2026-11-12 08:00:00');

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::A_VENIR)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$demain->id, $sansClasse->id])
            ->assertJsonPath('meta.total_seances', 2);
    }

    public function test_la_fenetre_suit_le_nombre_de_jours_demande(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $auteur = $this->membre($ecole, 'enseignant');
        $proche = $this->seance($classe, $auteur, '2026-11-16 23:00:00');
        $this->seance($classe, $auteur, '2026-11-18 08:00:00');

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::A_VENIR.'?days=7')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$proche->id])
            ->assertJsonPath('meta.date_fin', '2026-11-17');
    }

    /**
     * `teacher_id` désigne l'auteur, l'id du compte que la ligne rend dans
     * `enseignant.id` ; `classe_id`, l'id local que rend `/proxy/classes`.
     */
    public function test_la_gestion_filtre_par_auteur_et_par_classe_avec_les_ids_locaux(): void
    {
        $ecole = $this->ecole();
        $a = $this->classe($ecole, '6e A');
        $b = $this->classe($ecole, '6e B');
        $un = $this->membre($ecole, 'enseignant');
        $deux = $this->membre($ecole, 'enseignant');
        $cible = $this->seance($a, $un, '2026-11-12 08:00:00');
        $this->seance($b, $un, '2026-11-12 10:00:00');
        $this->seance($a, $deux, '2026-11-12 14:00:00');
        $coordinateur = $this->membre($ecole, 'coordinateur');

        $this->asTenant($coordinateur)
            ->getJson(self::A_VENIR.'?teacher_id='.$un->id.'&classe_id='.$a->id)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$cible->id])
            ->assertJsonPath('meta.filtres', ['teacher_id' => $un->id, 'classe_id' => $a->id]);
    }

    // ───────────────────────── l'enseignant

    public function test_un_enseignant_ne_lit_que_ses_seances_a_venir(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $lui = $this->membre($ecole, 'enseignant');
        $sienne = $this->seance($classe, $lui, '2026-11-12 08:00:00');
        // Même classe, autre auteur : une séance locale n'a pas de colonne matière,
        // la désignation dans la classe ne dit pas qu'elle est à lui (D119).
        $this->seance($classe, $this->membre($ecole, 'enseignant'), '2026-11-12 10:00:00');

        $this->asTenant($lui)
            ->getJson(self::A_VENIR.'?teacher_id=999999')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$sienne->id]);
    }

    public function test_l_emploi_du_temps_de_l_enseignant_suit_la_fenetre_glissante(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $lui = $this->membre($ecole, 'enseignant');
        $passee = $this->seance($classe, $lui, '2026-06-01 08:00:00');   // 5 mois avant
        $prochaine = $this->seance($classe, $lui, '2026-11-12 08:00:00');
        $this->seance($classe, $lui, '2027-06-01 08:00:00');             // 7 mois après
        $this->seance($classe, $this->membre($ecole, 'enseignant'), '2026-11-13 08:00:00');

        $reponse = $this->asTenant($lui)->getJson(self::ENSEIGNEMENT)->assertOk();

        $reponse->assertJsonPath('success', true)->assertJsonPath('data.*.id', [$passee->id, $prochaine->id]);
        self::assertSame($this->ligne($prochaine, $classe, $lui), $reponse->json('data.1'));
    }

    /**
     * La classe d'une ligne est celle que désigne `classe_id` : son libellé et
     * ses inscriptions actives, jamais le `classe_nom` libre de la séance.
     */
    public function test_la_classe_d_une_seance_est_lue_dans_la_classe(): void
    {
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($this->membre($ecole, 'etudiant'), $classe, 'actif');
        $this->inscrire($this->membre($ecole, 'etudiant'), $classe, 'inactif');
        $auteur = $this->membre($ecole, 'enseignant');
        app(LocalSeanceCreator::class)->create($auteur, [
            'titre' => 'Cours', 'date_seance' => '2026-11-12 08:00:00', 'matiere_nom' => 'Maths',
            'classe_nom' => '6e B', 'classe_id' => $classe->id,
        ]);

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::A_VENIR)
            ->assertOk()
            ->assertJsonPath('data.0.classe', ['id' => $classe->id, 'libelle' => '6e A', 'nom' => '6e A', 'name' => '6e A', 'effectif' => 1]);
    }

    /**
     * Une école basculée garde des séances miroirs sans auteur ni classe
     * locaux. La gestion les lit avec le nom d'enseignant qu'elles portent ;
     * aucun id n'est inventé (limite déclarée : enseignant et élève ne les
     * lisent pas, faute de lien local).
     */
    public function test_une_seance_miroir_d_une_ecole_basculee_garde_le_nom_de_son_enseignant(): void
    {
        $basculee = $this->ecole();
        Seance::factory()->create([
            'institution_id' => $basculee->id,
            'enseignant_nom' => 'Awa Koné',
            'date_seance' => '2026-11-12 08:00:00',
        ]);

        $this->asTenant($this->membre($basculee, 'coordinateur'))
            ->getJson(self::A_VENIR)
            ->assertOk()
            ->assertJsonPath('data.0.enseignant', ['id' => null, 'nom' => 'Awa Koné', 'prenom' => ''])
            ->assertJsonPath('data.0.classe.id', null);
    }

    // ───────────────────────── l'élève, et tout rôle non reconnu

    /** @return iterable<string, array{0: string}> */
    public static function apprenants(): iterable
    {
        yield 'élève' => ['etudiant'];
        // Un rôle non reconnu est traité comme un apprenant (#941).
        yield 'rôle non reconnu' => ['invite'];
    }

    #[DataProvider('apprenants')]
    public function test_un_apprenant_lit_les_seances_de_ses_classes_actives_sauf_les_masquees(string $role): void
    {
        $ecole = $this->ecole();
        $sienne = $this->classe($ecole, '6e A');
        $quittee = $this->classe($ecole, '6e B');
        $autre = $this->classe($ecole, '6e C');
        $eleve = $this->membre($ecole, $role);
        $this->inscrire($eleve, $sienne, 'actif');
        $this->inscrire($eleve, $quittee, 'inactif');
        $auteur = $this->membre($ecole, 'enseignant');
        $visible = $this->seance($sienne, $auteur, '2026-11-12 08:00:00');
        $masquee = $this->seance($sienne, $auteur, '2026-11-13 08:00:00');
        $this->masquer($masquee, $eleve, $ecole);
        $this->seance($quittee, $auteur, '2026-11-12 10:00:00');
        $this->seance($autre, $auteur, '2026-11-12 12:00:00');
        $this->seance(null, $auteur, '2026-11-12 14:00:00');   // sans classe : jamais à un élève (#941)

        $this->asTenant($eleve)->getJson(self::A_VENIR)->assertOk()->assertJsonPath('data.*.id', [$visible->id]);
        $this->asTenant($eleve)->getJson(self::MES_CLASSES)->assertOk()->assertJsonPath('data.*.id', [$visible->id]);
    }

    public function test_un_eleve_ne_lit_pas_les_seances_d_une_autre_classe_meme_filtree(): void
    {
        $ecole = $this->ecole();
        $sienne = $this->classe($ecole, '6e A');
        $autre = $this->classe($ecole, '6e B');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $sienne, 'actif');
        $this->seance($autre, $this->membre($ecole, 'enseignant'), '2026-11-12 08:00:00');

        $this->asTenant($eleve)
            ->getJson(self::A_VENIR.'?classe_id='.$autre->id)
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    /** Le filtre de classe vaut aussi hors de la gestion, dans ce que l'élève voit déjà. */
    public function test_un_eleve_inscrit_dans_deux_classes_filtre_sur_l_une(): void
    {
        $ecole = $this->ecole();
        $a = $this->classe($ecole, '6e A');
        $b = $this->classe($ecole, '6e B');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $a, 'actif');
        $this->inscrire($eleve, $b, 'actif');
        $auteur = $this->membre($ecole, 'enseignant');
        $deA = $this->seance($a, $auteur, '2026-11-12 08:00:00');
        $this->seance($b, $auteur, '2026-11-12 10:00:00');

        $this->asTenant($eleve)
            ->getJson(self::A_VENIR.'?classe_id='.$a->id)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$deA->id]);
    }

    public function test_un_eleve_sans_classe_active_recoit_le_404_d_aujourd_hui(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $this->classe($ecole, '6e A'), 'inactif');

        $this->asTenant($eleve)
            ->getJson(self::MES_CLASSES)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Aucune classe trouvée pour cet étudiant']);
    }

    // ───────────────────────── sans KLASSCI, et bornée

    public function test_aucun_appel_a_klassci_meme_pour_un_compte_porteur_d_un_jeton(): void
    {
        Http::fake();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
        $ecole = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['klassci_token' => 'jeton-residuel'])->save();
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['klassci_token' => 'jeton-residuel'])->save();
        $this->inscrire($eleve, $classe, 'actif');
        $this->seance($classe, $enseignant, '2026-11-12 08:00:00');

        $this->asTenant($enseignant)->getJson(self::A_VENIR)->assertOk()->assertJsonCount(1, 'data');
        $this->asTenant($enseignant)->getJson(self::ENSEIGNEMENT)->assertOk()->assertJsonCount(1, 'data');
        $this->asTenant($eleve)->getJson(self::MES_CLASSES)->assertOk()->assertJsonCount(1, 'data');
        Http::assertNothingSent();
    }

    public function test_sans_tenant_la_source_reste_bornee_a_l_ecole(): void
    {
        // Par HTTP, le scope global borne aussi. Sans tenant résolu, il est
        // fail-open : le bornage explicite doit tenir seul.
        $ecole = $this->ecole();
        $autre = $this->ecole();
        $classe = $this->classe($ecole, '6e A');
        $auteur = $this->membre($ecole, 'enseignant');
        $sienne = $this->seance($classe, $auteur, '2026-11-12 08:00:00');
        $this->seance($this->classe($autre, 'Voisine'), $this->membre($autre, 'enseignant'), '2026-11-12 08:00:00');
        // Une séance d'une AUTRE école qui pointe la classe de celle-ci : lue
        // sans borne, elle viendrait à l'élève par sa classe. Aucun écran ne la
        // crée ainsi ; la ligne est forcée pour prouver la borne.
        $this->seance($classe, $auteur, '2026-11-12 10:00:00')->forceFill(['institution_id' => $autre->id])->save();
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $classe, 'actif');
        app(TenantManager::class)->reset();
        $source = app(LocalSeancesListReader::class);

        self::assertSame([$sienne->id], array_column($source->getUpcomingForUser($this->membre($ecole, 'coordinateur'), 30, null, null)['data'], 'id'));
        self::assertSame([$sienne->id], array_column($source->getMyClassesForUser($eleve), 'id'));
    }

    public function test_un_compte_sans_etablissement_ne_lit_aucune_seance(): void
    {
        // Par HTTP, il est refusé avant le contrôleur (`no_institution`) : la
        // source ne doit pas en dépendre.
        $ecole = $this->ecole();
        $this->seance($this->classe($ecole, '6e A'), $this->membre($ecole, 'enseignant'), '2026-11-12 08:00:00');
        $sansEcole = User::factory()->create(['role' => 'coordinateur', 'institution_id' => null]);
        app(TenantManager::class)->reset();
        $source = app(LocalSeancesListReader::class);

        self::assertSame([], $source->getUpcomingForUser($sansEcole, 30, null, null)['data']);
        self::assertSame([], $source->getMyTeachingForUser($sansEcole));
        self::assertSame(['classe_missing' => true], $source->getMyClassesForUser($sansEcole));
    }

    // ───────────────────────── école créée depuis KLASSCI

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function routes(): iterable
    {
        yield 'à venir' => [self::A_VENIR, 'coordinateur'];
        yield 'emploi du temps enseignant' => [self::ENSEIGNEMENT, 'enseignant'];
        yield 'emploi du temps élève' => [self::MES_CLASSES, 'etudiant'];
    }

    /** Le refus d'aujourd'hui, inchangé : sans jeton, aucune donnée KLASSCI. */
    #[DataProvider('routes')]
    public function test_l_ecole_creee_depuis_klassci_exige_toujours_le_jeton(string $route, string $role): void
    {
        Http::fake();

        $this->asTenant($this->membre($this->ecole(InstitutionMode::Klassci), $role))
            ->getJson($route)
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'klassci_token_missing');
        Http::assertNothingSent();
    }

    /**
     * Le chemin du gestionnaire d'une école créée depuis KLASSCI garde son
     * comportement : séances miroirs seules, ids KLASSCI.
     */
    public function test_la_gestion_d_une_ecole_creee_depuis_klassci_garde_ses_ids_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $miroir = Seance::factory()->create([
            'institution_id' => $ecole->id,
            'klassci_seance_id' => 7001,
            'klassci_classe_id' => 41,
            'date_seance' => '2026-11-12 08:00:00',
        ]);
        $this->seance($this->classe($ecole, '6e A'), $this->membre($ecole, 'enseignant'), '2026-11-12 10:00:00');
        $coordinateur = $this->membre($ecole, 'coordinateur');
        $coordinateur->forceFill(['klassci_token' => 'jeton-personnel'])->save();

        $this->asTenant($coordinateur)
            ->getJson(self::A_VENIR)
            ->assertOk()
            ->assertJsonPath('data.*.id', [7001])
            ->assertJsonPath('data.0.classe.id', 41)
            ->assertJsonPath('data.0.enseignant.id', $miroir->klassci_enseignant_id)
            ->assertJsonPath('data.0.matiere.id', $miroir->klassci_matiere_id)
            ->assertJsonPath('data.0.enseignant.nom', $miroir->enseignant_nom)
            // Ce chemin non plus ne tient aucune heure de fin : elle est estimée, et le dit.
            ->assertJsonPath('data.0.heure_fin_estimee', true)
            ->assertJsonPath('data.0.programmation.heure_fin_estimee', true);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool}> */
    public static function finsReelles(): iterable
    {
        yield 'à venir, enseignant' => [self::A_VENIR, 'enseignant', false];
        yield 'emploi du temps enseignant' => [self::ENSEIGNEMENT, 'enseignant', true];
        yield 'emploi du temps élève' => [self::MES_CLASSES, 'etudiant', false];
    }

    /**
     * Une fin RÉELLE, rendue par KLASSCI, le dit : `heure_fin_estimee` vaut
     * `false`, dans `programmation` et, quand la ligne en porte une, à la racine.
     */
    #[DataProvider('finsReelles')]
    public function test_une_fin_rendue_par_klassci_n_est_pas_estimee(string $route, string $role, bool $finALaRacine): void
    {
        $date = '2026-11-12';
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($date): void {
            $mock->shouldReceive('requestWithUserToken')->andReturnUsing(static fn (string $jeton, string $chemin): array => match ($chemin) {
                'me/teacher-dashboard' => ['data' => ['matieres' => [['id' => 11, 'nom' => 'Maths', 'code' => 'MAT']]]],
                'me/dashboard' => ['data' => ['classe' => ['id' => 44], 'cours' => [['id' => 11, 'nom' => 'Maths']]]],
                default => ['data' => []],
            });
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
            $mock->shouldReceive('getEmploiTemps')->andReturn(['data' => [[
                'id' => 900,
                'matiere' => ['id' => 11],
                'programmation' => ['date' => $date, 'heure_debut' => $date.'T08:00:00', 'heure_fin' => $date.'T09:30:00'],
                'classe' => ['id' => 44, 'nom' => 'Classe 44'],
            ]]]);
        });
        $compte = $this->membre($this->ecole(InstitutionMode::Klassci), $role);
        $compte->forceFill(['klassci_token' => 'jeton-personnel', 'klassci_id' => 555])->save();

        $reponse = $this->asTenant($compte)->getJson($route)->assertOk()->assertJsonPath('data.0.programmation.heure_fin_estimee', false);

        if ($finALaRacine) {
            $reponse->assertJsonPath('data.0.heure_fin_estimee', false);
        }
    }

    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        // Laravel garde le contrôleur sur l'objet Route : une source reçue par
        // le constructeur resterait celle de la première école servie.
        $locale = $this->ecole();
        $auteur = $this->membre($locale, 'enseignant');
        $seance = $this->seance($this->classe($locale, '6e A'), $auteur, '2026-11-12 08:00:00');

        $this->asTenant($this->membre($this->ecole(InstitutionMode::Klassci), 'enseignant'))
            ->getJson(self::ENSEIGNEMENT)
            ->assertUnauthorized();
        $this->asTenant($auteur)
            ->getJson(self::ENSEIGNEMENT)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$seance->id]);
    }

    // ───────────────────────── la panne

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function pannes(): iterable
    {
        yield 'à venir' => [self::A_VENIR, 'coordinateur'];
        yield 'emploi du temps enseignant' => [self::ENSEIGNEMENT, 'enseignant'];
        yield 'emploi du temps élève' => [self::MES_CLASSES, 'etudiant'];
    }

    /**
     * Une panne de base de la source locale est une `RuntimeException` : elle
     * passe par le rendu des échecs, qui la rapporte une fois, sans le message
     * ni les valeurs de la requête (#948), et rend le 500 générique.
     */
    #[DataProvider('pannes')]
    public function test_une_panne_de_base_est_rapportee_une_fois_sans_ses_valeurs(string $route, string $role): void
    {
        $this->app->instance(LocalSeancesListReader::class, new class implements SeancesListReader
        {
            public function getUpcomingForUser(User $user, int $days, ?int $teacherId, ?int $classeId): array
            {
                throw self::panne();
            }

            public function getMyTeachingForUser(User $user): array
            {
                throw self::panne();
            }

            public function getMyClassesForUser(User $user): array
            {
                throw self::panne();
            }

            private static function panne(): QueryException
            {
                return new QueryException(
                    'sqlite',
                    'select * from seances where email = ?',
                    ['temoin.sql.lot3d@exemple.invalid'],
                    new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
                );
            }
        });
        $this->captureWrittenLog();

        $this->asTenant($this->membre($this->ecole(), $role))
            ->getJson($route)
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Une erreur est survenue.']);

        $ecrits = $this->recordsAtLeast(Level::Error);
        self::assertCount(1, $ecrits, 'La panne doit être écrite une fois.');
        self::assertSame('select * from seances where email = ?', $ecrits[0]->context['sql'] ?? null);
        $this->assertNoWitnessWritten(self::TEMOIN_SQL);
    }

    // ───────────────────────── harnais

    /**
     * Comme le produit : l'élève masque la séance pendant une requête, le
     * tenant résolu. La ligne porte alors l'établissement ; posée sans tenant,
     * elle n'en porterait aucun, et le scope de la requête suivante l'écarterait.
     */
    private function masquer(Seance $seance, User $eleve, Institution $ecole): void
    {
        $tenants = app(TenantManager::class);
        $tenants->set($ecole);
        SeanceUserHidden::hide($seance->id, $eleve->id);
        $tenants->reset();
    }

    /** Par le service du produit, celui de l'écran de création d'une séance. */
    private function seance(?Classe $classe, User $auteur, string $quand): Seance
    {
        return app(LocalSeanceCreator::class)->create($auteur, [
            'titre' => 'Cours',
            'date_seance' => $quand,
            'matiere_nom' => 'Maths',
            'classe_nom' => $classe?->libelle,
            'classe_id' => $classe?->id,
        ]);
    }

    /**
     * La ligne attendue d'une séance locale. Les champs de visio viennent de la
     * séance relue : ce test ne porte que sur l'identité et l'horaire.
     *
     * @return array<string, mixed>
     */
    private function ligne(Seance $seance, Classe $classe, User $auteur): array
    {
        $seance = $seance->fresh();
        self::assertInstanceOf(Seance::class, $seance);
        $debut = Carbon::parse($seance->date_seance);
        $fin = $debut->copy()->addHours(2);
        $visio = [
            'visio_enabled' => $seance->visio_enabled,
            'visio_type' => $seance->visio_type,
            'visio_room_id' => $seance->visio_room_id,
            'visio_active' => $seance->visio_active,
            'visio_status' => $seance->visio_status,
            'visio_started_at' => null,
            'visio_ended_at' => null,
            'visio_participants_count' => 0,
        ];

        return [
            'id' => $seance->id,
            'klassci_seance_id' => null,
            // Le titre de la ligne (`TitreDeLaSeanceTest` en fixe les deux volets).
            'titre' => $seance->titre,
            'date_seance' => $debut->format('Y-m-d'),
            'date_debut' => $debut->toISOString(),
            'date_fin' => $fin->toISOString(),
            'heure_debut' => $debut->format('H:i'),
            'heure_fin' => $fin->format('H:i'),
            'heure_fin_estimee' => true,
            'salle' => null,
            'programmation' => [
                'date' => $debut->format('Y-m-d'),
                'heure_debut' => $debut->toISOString(),
                'heure_fin' => $fin->toISOString(),
                'heure_fin_estimee' => true,
                'salle' => null,
            ],
            'matiere' => ['id' => null, 'libelle' => 'Maths', 'nom' => 'Maths', 'code' => null],
            'classe' => ['id' => $classe->id, 'libelle' => $classe->libelle, 'nom' => $classe->libelle, 'name' => $classe->libelle, 'effectif' => 0],
            'enseignant' => ['id' => $auteur->id, 'nom' => $auteur->name, 'prenom' => ''],
            'visio' => [
                'enabled' => $seance->visio_enabled,
                'active' => $seance->visio_active,
                'status' => $seance->visio_status,
                'room_id' => $seance->visio_room_id,
                'started_at' => null,
                'ended_at' => null,
                'participants_count' => 0,
            ],
            'statut' => $seance->visio_status ?? 'programme',
        ] + $visio;
    }
}
