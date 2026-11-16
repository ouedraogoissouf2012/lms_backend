<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Klassci\Auth\StudentClassSynchronizer;
use App\Services\KlassciProxyService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * La classe KLASSCI de l'élève, écrite au login (#969).
 *
 * `StudentClassSynchronizer` est le seul écrivain de `user_classes`. Il écrit la
 * nouvelle classe AVANT d'effacer les autres : un échec ne laisse jamais l'élève
 * sans sa classe précédente. Ses sorties sont distinguées (écrite, sans classe,
 * réponse inattendue, échec) et journalisées chacune à son niveau ; le login ne
 * casse jamais.
 *
 * Le volet autonome n'a pas ce chemin : son élève se connecte en local, et son
 * inscription est `classe_etudiant`. Un témoin le prouve.
 */
final class ClasseDeLEleveAuLoginTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $journal;

    protected function tearDown(): void
    {
        UserClass::flushEventListeners();
        parent::tearDown();
    }

    public function test_une_premiere_connexion_ecrit_la_classe(): void
    {
        $eleve = $this->eleve();

        $issue = $this->synchroniser($eleve, ['data' => ['classe' => ['id' => 7001, 'name' => 'BTS 1']]]);

        self::assertSame([7001], $this->classesDe($eleve));
        self::assertSame('ecrite', $issue?->value);
        self::assertTrue($this->journal->hasInfoThatContains('Classe KLASSCI de l\'élève synchronisée'));
    }

    public function test_un_changement_de_classe_ne_garde_que_la_nouvelle(): void
    {
        $eleve = $this->eleve();
        $this->ligne($eleve, 7001);

        $this->synchroniser($eleve, ['data' => ['classe' => ['id' => 7002, 'name' => 'BTS 2']]]);

        self::assertSame([7002], $this->classesDe($eleve));
    }

    public function test_la_meme_classe_reste_une_seule_ligne_mise_a_jour(): void
    {
        $eleve = $this->eleve();
        $this->ligne($eleve, 7001);

        $this->synchroniser($eleve, ['data' => ['classe' => ['id' => 7001, 'name' => 'BTS 1 renommée']]]);

        self::assertSame([7001], $this->classesDe($eleve));
        self::assertSame('BTS 1 renommée', UserClass::query()->where('user_id', $eleve->id)->value('classe_nom'));
    }

    /** Le cœur de #969 : une écriture en échec n'efface pas la classe précédente. */
    public function test_un_echec_a_l_ecriture_garde_la_classe_precedente(): void
    {
        $eleve = $this->eleve();
        $this->ligne($eleve, 7001);
        UserClass::saving(static function (): never {
            throw new RuntimeException('écriture en panne');
        });

        $issue = $this->synchroniser($eleve, ['data' => ['classe' => ['id' => 7002, 'name' => 'BTS 2']]]);

        self::assertSame([7001], $this->classesDe($eleve), 'L\'élève a perdu sa classe précédente.');
        self::assertSame('echec', $issue?->value);
        self::assertTrue($this->journal->hasErrorThatContains('Échec de la synchronisation de la classe de l\'élève'));
    }

    public function test_un_identifiant_de_classe_non_numerique_ne_touche_a_rien(): void
    {
        $eleve = $this->eleve();
        $this->ligne($eleve, 7001);

        $issue = $this->synchroniser($eleve, ['data' => ['classe' => ['name' => 'Sans identifiant']]]);

        self::assertSame([7001], $this->classesDe($eleve), 'L\'élève a perdu sa classe précédente.');
        self::assertSame('reponse_inattendue', $issue?->value);
    }

    public function test_une_reponse_sans_classe_est_inattendue_et_ne_touche_a_rien(): void
    {
        $eleve = $this->eleve();
        $this->ligne($eleve, 7001);

        $issue = $this->synchroniser($eleve, ['data' => ['cours' => []]]);

        self::assertSame([7001], $this->classesDe($eleve));
        self::assertSame('reponse_inattendue', $issue?->value);
        self::assertTrue($this->journal->hasWarningThatContains('Réponse KLASSCI inattendue pour la classe de l\'élève'));
    }

    public function test_un_eleve_sans_classe_chez_klassci_est_distingue_et_garde_sa_ligne(): void
    {
        $eleve = $this->eleve();
        $this->ligne($eleve, 7001);

        $issue = $this->synchroniser($eleve, ['data' => ['classe' => null]]);

        self::assertSame([7001], $this->classesDe($eleve));
        self::assertSame('sans_classe', $issue?->value);
        self::assertTrue($this->journal->hasInfoThatContains('Élève sans classe chez KLASSCI'));
    }

    public function test_un_echec_de_l_appel_klassci_garde_la_classe_precedente(): void
    {
        $eleve = $this->eleve();
        $this->ligne($eleve, 7001);

        $issue = $this->synchroniser($eleve, static function (): never {
            throw new RuntimeException('KLASSCI injoignable');
        });

        self::assertSame([7001], $this->classesDe($eleve));
        self::assertSame('echec', $issue?->value);
        self::assertTrue($this->journal->hasErrorThatContains('Échec de la synchronisation de la classe de l\'élève'));
    }

    /** Volet autonome : le login local n'appelle pas KLASSCI, donc pas ce synchroniseur. */
    public function test_ecole_autonome_le_login_de_l_eleve_n_ecrit_aucune_classe_klassci(): void
    {
        Http::fake();
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        $eleve = User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => 'etudiant',
            'email' => 'eleve@ecole-autonome.test',
            'password' => bcrypt('secret-pass'),
            'klassci_id' => null,
            'klassci_token' => null,
        ]);

        $this->postJson('/api/auth/login', ['username' => 'eleve@ecole-autonome.test', 'password' => 'secret-pass'])->assertOk();

        Http::assertNothingSent();
        self::assertSame([], $this->classesDe($eleve));
    }

    // ───────────────────────── fixtures

    private function eleve(): User
    {
        return User::factory()->create([
            'institution_id' => Institution::factory()->create()->id,
            'role' => 'etudiant',
        ]);
    }

    private function ligne(User $eleve, int $klassciClasseId): void
    {
        UserClass::query()->create([
            'user_id' => $eleve->id,
            'institution_id' => $eleve->institution_id,
            'klassci_classe_id' => $klassciClasseId,
            'classe_nom' => 'Classe '.$klassciClasseId,
            'synced_at' => now(),
        ]);
    }

    /** @return list<int> */
    private function classesDe(User $eleve): array
    {
        return array_map('intval', UserClass::query()->withoutGlobalScopes()->where('user_id', $eleve->id)->orderBy('klassci_classe_id')->pluck('klassci_classe_id')->all());
    }

    /**
     * Le synchroniseur du conteneur, avec KLASSCI simulé et un journal lisible.
     *
     * @param  array<string, mixed>|Closure(): never  $reponse
     */
    private function synchroniser(User $eleve, array|Closure $reponse): mixed
    {
        $proxy = Mockery::mock(KlassciProxyService::class);
        $attente = $proxy->shouldReceive('requestWithUserToken');
        $reponse instanceof Closure ? $attente->andReturnUsing($reponse) : $attente->andReturn($reponse);
        $this->instance(KlassciProxyService::class, $proxy);

        $this->journal = new TestHandler;
        $this->instance(LoggerInterface::class, new Logger('test', [$this->journal]));

        return app(StudentClassSynchronizer::class)->sync($eleve, 'jeton-klassci');
    }
}
