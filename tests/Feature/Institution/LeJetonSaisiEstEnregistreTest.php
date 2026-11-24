<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Support\Log\JournalRelu;
use Tests\TestCase;

/**
 * #1332 — le jeton API KLASSCI saisi à l'écran d'administration est enregistré.
 *
 * Depuis #30, `Institution::$fillable` ne porte plus l'alias `klassci_api_token` :
 * `create()` et `update()` le jetaient en silence. Les fabriques, elles, passent par
 * `Model::unguarded()` : c'est ce qui masquait le défaut.
 *
 * Ces tests relisent la ligne EN BASE et déchiffrent le jeton. En modification, un champ
 * laissé vide (clé absente, `null` ou `''`) garde l'ancien jeton : c'est ce que l'écran
 * annonce. L'effacement au détachement relève de #1213.
 *
 * Compte supradmin plateforme, vrai jeton, les deux types d'école.
 */
final class LeJetonSaisiEstEnregistreTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const URL = 'https://ecole.klassci.test/api/lms';

    private const ANCIEN = 'ancien-jeton-de-l-ecole';

    private const NOUVEAU = 'nouveau-jeton-saisi-a-l-ecran';

    private JournalRelu $journal;

    protected function setUp(): void
    {
        parent::setUp();

        // Le journal applicatif est relu : la valeur du jeton ne doit jamais y entrer.
        $this->journal = new JournalRelu;
        $this->app->instance(LoggerInterface::class, $this->journal);
    }

    public function test_a_la_creation_le_jeton_saisi_est_enregistre_chiffre(): void
    {
        $this->creer(['slug' => 'ecole-klassci', 'klassci_api_url' => self::URL, 'klassci_api_token' => self::NOUVEAU])
            ->assertCreated();

        $ecole = Institution::query()->where('slug', 'ecole-klassci')->sole();
        self::assertSame(self::NOUVEAU, $ecole->klassci_api_token);
        self::assertNotSame(self::NOUVEAU, $this->ligne($ecole)['klassci_api_token_encrypted'], 'Le jeton est stocké en clair.');
    }

    public function test_a_la_creation_une_ecole_autonome_sans_jeton_n_en_a_aucun(): void
    {
        $this->creer(['slug' => 'ecole-autonome', 'mode' => InstitutionMode::Standalone->value, 'klassci_api_token' => ''])
            ->assertCreated();

        self::assertNull(Institution::query()->where('slug', 'ecole-autonome')->sole()->klassci_api_token);
    }

    public function test_en_modification_un_nouveau_jeton_remplace_l_ancien(): void
    {
        $ecole = $this->ecole('klassci');

        $this->modifier($ecole, ['klassci_api_token' => self::NOUVEAU])->assertOk();

        self::assertSame(self::NOUVEAU, $ecole->fresh()?->klassci_api_token);
    }

    /** Clé absente (ce qu'envoie l'écran), `null` ou `''` (un autre client) : l'ancien reste. */
    #[DataProvider('volets')]
    public function test_en_modification_un_champ_vide_garde_l_ancien_jeton(string $volet): void
    {
        foreach ([[], ['klassci_api_token' => null], ['klassci_api_token' => '']] as $charge) {
            $ecole = $this->ecole($volet);

            $this->modifier($ecole, $charge + ['name' => 'Nouveau nom'])->assertOk();

            self::assertSame(self::ANCIEN, $ecole->fresh()?->klassci_api_token, json_encode($charge) ?: '');
        }
    }

    public function test_la_valeur_n_entre_ni_dans_la_reponse_ni_dans_le_journal(): void
    {
        $creation = $this->creer(['slug' => 'ecole-klassci', 'klassci_api_url' => self::URL, 'klassci_api_token' => self::ANCIEN]);
        $ecole = Institution::query()->where('slug', 'ecole-klassci')->sole();
        $modification = $this->modifier($ecole, ['klassci_api_token' => self::NOUVEAU]);

        foreach ([$creation, $modification] as $reponse) {
            self::assertStringNotContainsString(self::ANCIEN, $reponse->getContent() ?: '');
            self::assertStringNotContainsString(self::NOUVEAU, $reponse->getContent() ?: '');
        }
        self::assertNotSame([], $this->journal->lignes, 'Le journal applicatif n\'a rien reçu : le test serait vide.');
        foreach ($this->journal->lignes as $ligne) {
            self::assertStringNotContainsString(self::ANCIEN, $ligne);
            self::assertStringNotContainsString(self::NOUVEAU, $ligne);
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => ['klassci'];
        yield 'ecole autonome' => ['standalone'];
    }

    /** @param  array<string, mixed>  $charge */
    private function creer(array $charge): TestResponse
    {
        return $this->asTenant($this->supradmin())->postJson('/api/admin/institutions', $charge + ['name' => 'École']);
    }

    /** @param  array<string, mixed>  $charge */
    private function modifier(Institution $ecole, array $charge): TestResponse
    {
        return $this->asTenant($this->supradmin())->putJson("/api/admin/institutions/{$ecole->id}", $charge);
    }

    private function supradmin(): User
    {
        return User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);
    }

    private function ecole(string $volet): Institution
    {
        $fabrique = $volet === 'standalone' ? Institution::factory()->standalone() : Institution::factory();

        return $fabrique->create([
            'klassci_api_url' => $volet === 'standalone' ? null : self::URL,
            'klassci_api_token' => self::ANCIEN,
        ]);
    }

    /** @return array<string, mixed> */
    private function ligne(Institution $ecole): array
    {
        return (array) DB::table('institutions')->where('id', $ecole->id)->first();
    }
}
