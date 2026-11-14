<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * La synchronisation d'une classe suit sa liste d'élèves COMPLÈTE (école KLASSCI).
 *
 * Un élève qui n'est plus dans la liste de sa classe y passe `inactif`, jamais
 * supprimé. La liste ne fait foi que si elle est prouvée complète : bloc présent,
 * `places_occupees` entier égal au nombre reçu, identifiants entiers sans doublon.
 * Sinon rien ne change, et l'écart est journalisé. Les absents se jugent sur la
 * liste REÇUE, jamais sur le résultat de l'écriture.
 */
final class ListeCompleteDesElevesTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const INCOMPLETE = 'Liste des eleves incomplete : aucune inscription desactivee';

    private Institution $ecole;

    private Classe $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole(InstitutionMode::Klassci);
        app(TenantManager::class)->set($this->ecole);
        $this->a = $this->classeKlassci(71, '6e A');
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /** 1. A puis B : l'ancienne classe fermée sans suppression, la nouvelle ouverte. */
    public function test_un_eleve_parti_de_sa_classe_y_passe_inactif_et_lit_sa_nouvelle_classe(): void
    {
        $b = $this->classeKlassci(72, '5e A');
        $parti = $this->eleve(501, $this->a);
        $reste = $this->eleve(502, $this->a);
        UserClass::query()->withoutGlobalScopes()->create(['user_id' => $parti->id, 'institution_id' => $this->ecole->id, 'klassci_classe_id' => 72]);
        $leconA = Lesson::factory()->published()->create(['institution_id' => $this->ecole->id, 'classe_id' => $this->a->id]);
        $leconB = Lesson::factory()->published()->create(['institution_id' => $this->ecole->id, 'classe_id' => $b->id]);
        $this->klassciRend([71 => [[502], 1], 72 => [[501], 1]]);

        $this->synchroniser(71);

        self::assertSame('inactif', $this->statut($this->a, $parti), 'la ligne existe, inactive');
        self::assertSame('actif', $this->statut($this->a, $reste));
        self::assertSame([$leconB->id], $this->leconsDe($parti), 'sans ligne active, le repli lit la classe courante');

        $this->synchroniser(72);

        self::assertSame('actif', $this->statut($b, $parti));
        self::assertNotContains($leconA->id, $this->leconsDe($parti));
    }

    /** 2. Liste incomplète : rien ne change, l'écart est journalisé. */
    public function test_une_liste_incomplete_ne_desactive_rien_et_le_dit(): void
    {
        $journal = Mockery::spy(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $journal);
        $absent = $this->eleve(501, $this->a);
        $this->eleve(502, $this->a);
        $this->klassciRend([71 => [[502], 3]]);

        $this->synchroniser(71);

        self::assertSame('actif', $this->statut($this->a, $absent));
        $journal->shouldHaveReceived('warning')
            ->withArgs(static fn (string $message, array $contexte = []): bool => $message === self::INCOMPLETE
                && ($contexte['recus'] ?? null) === 1 && ($contexte['annonces'] ?? null) === 3)
            ->once();
    }

    /** 3. Bloc `etudiants` absent, par identifiant et à la connexion : rien ne change. */
    public function test_un_bloc_absent_ne_desactive_rien(): void
    {
        $absent = $this->eleve(501, $this->a);
        $this->klassciRend([71 => [null, 0]]);

        $this->synchroniser(71);
        self::assertSame('actif', $this->statut($this->a, $absent), 'par identifiant');

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'classes', 'GET')->andReturn(['data' => [['id' => 71, 'libelle' => '6e A']]]);
            $mock->shouldReceive('fetchManyClassesDetails')->with([71], 'jeton')
                ->andReturn([71 => ['data' => ['classe' => ['id' => 71, 'libelle' => '6e A', 'places_occupees' => 0]]]]);
        });
        app(ClasseSyncService::class)->syncUserClasses('jeton', 'coordinateur');
        self::assertSame('actif', $this->statut($this->a, $absent), 'à la connexion');
    }

    /** 4. `places_occupees` absent ou non entier : rien ne change. */
    public function test_sans_effectif_annonce_entier_rien_ne_change(): void
    {
        $absent = $this->eleve(501, $this->a);
        $this->eleve(502, $this->a);

        foreach ([self::SANS_EFFECTIF, '1a', 1.5] as $places) {
            $this->klassciRend([71 => [[502], $places]]);
            $this->synchroniser(71);
            self::assertSame('actif', $this->statut($this->a, $absent), 'effectif annoncé : '.var_export($places, true));
        }
    }

    /** 5. Un identifiant inutilisable, ou en double : rien ne change. */
    public function test_un_identifiant_inutilisable_ou_en_double_ne_desactive_rien(): void
    {
        $absent = $this->eleve(501, $this->a);
        $this->eleve(502, $this->a);

        foreach ([[['id' => 502], ['nom' => 'Sans identifiant']], [['id' => 502], ['id' => 502]], [['id' => 502], ['id' => 'x']]] as $liste) {
            $this->klassciBrut(71, $liste, 2);
            $this->synchroniser(71);
            self::assertSame('actif', $this->statut($this->a, $absent), json_encode($liste) ?: '');
        }
    }

    /** 6. Un élève sans identifiant KLASSCI n'est pas touché ; la réconciliation a bien lieu. */
    public function test_un_eleve_sans_identifiant_klassci_n_est_pas_touche(): void
    {
        $local = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($local, $this->a, 'actif');
        $absent = $this->eleve(501, $this->a);
        $this->eleve(502, $this->a);
        $this->klassciRend([71 => [[502], 1]]);

        $this->synchroniser(71);

        self::assertSame('actif', $this->statut($this->a, $local));
        self::assertSame('inactif', $this->statut($this->a, $absent));
    }

    /** 7. Une ligne rattachée à un autre établissement reste intacte. */
    public function test_une_ligne_d_un_autre_etablissement_reste_intacte(): void
    {
        $ailleurs = $this->ecole(InstitutionMode::Klassci);
        $etranger = $this->membre($ailleurs, 'etudiant');
        $etranger->forceFill(['klassci_id' => 900])->save();
        DB::table('classe_etudiant')->insert(['classe_id' => $this->a->id, 'user_id' => $etranger->id, 'institution_id' => $ailleurs->id, 'statut' => 'actif', 'date_inscription' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $absent = $this->eleve(501, $this->a);
        $this->eleve(502, $this->a);
        $this->klassciRend([71 => [[502], 1]]);

        $this->synchroniser(71);

        self::assertSame('actif', $this->statut($this->a, $etranger));
        self::assertSame('inactif', $this->statut($this->a, $absent));
    }

    /** 8. Un élève listé dont l'écriture échoue n'est pas jugé absent. */
    public function test_un_eleve_liste_dont_l_ecriture_echoue_n_est_pas_desactive(): void
    {
        $echoue = $this->eleve(501, $this->a);
        $this->eleve(502, $this->a);
        $absent = $this->eleve(503, $this->a);
        $this->klassciRend([71 => [[501, 502], 2]]);
        $fait = false;
        DB::beforeExecuting(function (string $requete, array $valeurs) use (&$fait, $echoue): void {
            if (! $fait && preg_match('/^update [`"]classe_etudiant[`"]/i', $requete) === 1 && end($valeurs) === $echoue->id) {
                $fait = true;
                throw new RuntimeException('écriture refusée pour le test');
            }
        });

        $this->synchroniser(71);

        self::assertTrue($fait, 'l\'écriture de l\'élève listé a été tentée');
        self::assertSame('actif', $this->statut($this->a, $echoue));
        self::assertSame('inactif', $this->statut($this->a, $absent));
    }

    /** 9. Un élève désactivé puis de nouveau listé repasse `actif`. */
    public function test_un_eleve_de_nouveau_liste_repasse_actif(): void
    {
        $revenu = $this->eleve(501, $this->a);
        $this->eleve(502, $this->a);
        $this->klassciRend([71 => [[502], 1]]);
        $this->synchroniser(71);
        self::assertSame('inactif', $this->statut($this->a, $revenu));

        $this->klassciRend([71 => [[501, 502], 2]]);
        $this->synchroniser(71);

        self::assertSame('actif', $this->statut($this->a, $revenu));
    }

    /**
     * 10. Une classe vidée (liste vide, effectif 0) : les lignes de ses élèves connus de
     * KLASSCI passent `inactif`. Celle d'un élève sans identifiant KLASSCI reste
     * `actif` : avec une liste vide, `whereNotIn` devient `1 = 1` (Laravel), et seule
     * la garde sur l'identifiant l'écarte.
     */
    public function test_une_classe_videe_desactive_toutes_ses_lignes(): void
    {
        $un = $this->eleve(501, $this->a);
        $deux = $this->eleve(502, $this->a);
        $local = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($local, $this->a, 'actif');
        $this->klassciRend([71 => [[], 0]]);

        $this->synchroniser(71);

        self::assertSame('inactif', $this->statut($this->a, $un));
        self::assertSame('inactif', $this->statut($this->a, $deux));
        self::assertSame('actif', $this->statut($this->a, $local), 'un élève sans identifiant KLASSCI n\'est pas jugé par la liste');
        self::assertSame(3, DB::table('classe_etudiant')->where('classe_id', $this->a->id)->count(), 'jamais de suppression');
    }

    private const SANS_EFFECTIF = 'sans effectif';

    private function classeKlassci(int $klassciId, string $libelle): Classe
    {
        $classe = $this->classe($this->ecole, $libelle);
        $classe->forceFill(['klassci_id' => $klassciId])->save();

        return $classe;
    }

    /** Un élève connu de KLASSCI, inscrit `actif` dans la classe. */
    private function eleve(int $klassciId, Classe $classe): User
    {
        $eleve = $this->membre($this->ecole, 'etudiant');
        $eleve->forceFill(['klassci_id' => $klassciId])->save();
        $this->inscrire($eleve, $classe, 'actif');

        return $eleve;
    }

    /**
     * La réponse de `classes/{id}` par classe : [identifiants listés ou null (bloc absent), effectif annoncé].
     *
     * @param  array<int, array{0: list<int>|null, 1: mixed}>  $classes
     */
    private function klassciRend(array $classes): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($classes): void {
            foreach ($classes as $id => [$ids, $places]) {
                $liste = $ids === null ? null : array_map(static fn (int $k): array => ['id' => $k, 'nom_complet' => "Élève {$k}"], $ids);
                $mock->shouldReceive('requestWithUserToken')->with('jeton', "classes/{$id}", 'GET')->andReturn(['data' => self::enveloppe($id, $liste, $places)]);
            }
        });
    }

    /** @param  list<array<string, mixed>>  $liste */
    private function klassciBrut(int $id, array $liste, int $places): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($id, $liste, $places): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton', "classes/{$id}", 'GET')->andReturn(['data' => self::enveloppe($id, $liste, $places)]);
        });
    }

    /**
     * @param  list<array<string, mixed>>|null  $liste
     * @return array<string, mixed>
     */
    private static function enveloppe(int $id, ?array $liste, mixed $places): array
    {
        $classe = ['id' => $id, 'libelle' => "Classe {$id}"] + ($places === self::SANS_EFFECTIF ? [] : ['places_occupees' => $places]);

        return ['classe' => $classe] + ($liste === null ? [] : ['etudiants' => $liste]);
    }

    private function synchroniser(int $klassciClasseId): void
    {
        self::assertNotNull(app(ClasseSyncService::class)->syncClasseById($klassciClasseId, 'jeton'));
    }

    private function statut(Classe $classe, User $eleve): mixed
    {
        return DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('user_id', $eleve->id)->value('statut');
    }

    /** @return list<int> */
    private function leconsDe(User $eleve): array
    {
        $lecons = $this->asTenant($eleve)->getJson('/api/lessons/my-courses')->assertOk()->json('data');

        return array_values(array_map(static fn (array $lecon): int => (int) $lecon['id'], is_array($lecons) ? $lecons : []));
    }
}
