<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\Lifecycle\VisioDeactivation;
use App\Services\Visio\Lifecycle\VisioSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * Contrat de `start-visio`, `end-visio` et `deactivate-visio` : chaque réponse
 * RÉELLE suit ce que `docs/openapi.yaml` en dit, statut et corps, dans les deux
 * types d'école. Vrai jeton porteur (`asTenant`).
 */
final class VisioCycleContractTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    /** Le geste, et l'état de fabrique de la séance qui le permet. */
    private const GESTES = ['start' => 'withVisio', 'end' => 'visioActive', 'deactivate' => 'withVisio'];

    /** L'état qui refuse le geste en 400 : désactiver n'en a pas (une visio non activée rend 404). */
    private const ETATS_REFUSES = [
        'start' => ['visio_enabled' => false],
        'end' => ['visio_status' => 'programmee', 'visio_active' => false],
    ];

    private Institution $ecole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
        Queue::fake();
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function gestesEtVolets(): iterable
    {
        yield from self::croiser(array_keys(self::GESTES));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function gestesRefusablesEtVolets(): iterable
    {
        yield from self::croiser(array_keys(self::ETATS_REFUSES));
    }

    /**
     * @param  list<string>  $gestes
     * @return iterable<string, array{0: string, 1: string}>
     */
    private static function croiser(array $gestes): iterable
    {
        foreach ($gestes as $geste) {
            foreach (['ecole klassci' => 'klassci', 'ecole autonome' => 'standalone'] as $nom => $volet) {
                yield "{$geste}, {$nom}" => [$geste, $volet];
            }
        }
    }

    #[DataProvider('gestesEtVolets')]
    public function test_200_le_proprietaire(string $geste, string $volet): void
    {
        $this->ecole($volet);
        $proprietaire = $this->compte('teacher');
        $seance = $this->seanceDe($proprietaire, self::GESTES[$geste]);

        $reponse = $this->asTenant($proprietaire)->postJson(self::url($seance->id, $geste))->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, self::chemin($geste), 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::chemin($geste), 'post', 'data');
    }

    /** Démarrer une visio non activée ; terminer une visio qui n'est pas active. */
    #[DataProvider('gestesRefusablesEtVolets')]
    public function test_400_un_etat_qui_ne_permet_pas_le_geste(string $geste, string $volet): void
    {
        $this->ecole($volet);
        $proprietaire = $this->compte('teacher');
        $seance = $this->seanceDe($proprietaire, 'withVisio', self::ETATS_REFUSES[$geste]);

        $reponse = $this->asTenant($proprietaire)->postJson(self::url($seance->id, $geste))->assertStatus(400);

        $this->assertMatchesOpenApiResponse($reponse, self::chemin($geste), 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::chemin($geste), 'post', 'message');
    }

    #[DataProvider('gestesEtVolets')]
    public function test_401_sans_jeton_porteur(string $geste, string $volet): void
    {
        $this->ecole($volet);

        $reponse = $this->postJson(self::url(4242, $geste))->assertUnauthorized();

        $this->assertMatchesOpenApiResponse($reponse, self::chemin($geste), 'post');
    }

    #[DataProvider('gestesEtVolets')]
    public function test_403_un_role_non_admis_et_un_enseignant_qui_ne_gere_pas(string $geste, string $volet): void
    {
        $this->ecole($volet);
        $seance = $this->seanceDe($this->compte('teacher'), self::GESTES[$geste]);

        $role = $this->asTenant($this->compte('student'))->postJson(self::url($seance->id, $geste))->assertForbidden();
        $this->assertMatchesOpenApiResponse($role, self::chemin($geste), 'post');

        $gestion = $this->asTenant($this->compte('teacher'))->postJson(self::url($seance->id, $geste))->assertForbidden();
        $this->assertMatchesOpenApiResponse($gestion, self::chemin($geste), 'post');
        $gestion->assertJsonPath('reason', 'actor_not_authorized');
    }

    #[DataProvider('gestesEtVolets')]
    public function test_404_une_seance_introuvable(string $geste, string $volet): void
    {
        $this->ecole($volet);

        $reponse = $this->asTenant($this->compte('teacher'))->postJson(self::url(4242, $geste))->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::chemin($geste), 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::chemin($geste), 'post', 'message');
    }

    /** Une erreur rattrapée par le service : le journal du geste, appelé après l'écriture, échoue. */
    #[DataProvider('gestesEtVolets')]
    public function test_500_une_erreur_interne(string $geste, string $volet): void
    {
        $this->app->when($geste === 'deactivate' ? VisioDeactivation::class : VisioSessionService::class)
            ->needs(LoggerInterface::class)
            ->give(static fn (): LoggerInterface => new class extends AbstractLogger
            {
                public function log($level, string|Stringable $message, array $context = []): void
                {
                    if ($level === 'info') {
                        throw new RuntimeException('panne provoquée par le test');
                    }
                }
            });
        $this->ecole($volet);
        $proprietaire = $this->compte('teacher');
        $seance = $this->seanceDe($proprietaire, self::GESTES[$geste]);

        $reponse = $this->asTenant($proprietaire)->postJson(self::url($seance->id, $geste))->assertStatus(500);

        $this->assertMatchesOpenApiResponse($reponse, self::chemin($geste), 'post');
        $reponse->assertJsonPath('error', 'Une erreur est survenue.');
    }

    private function ecole(string $volet): void
    {
        $this->ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
    }

    private function compte(string $role): User
    {
        return User::factory()->{$role}()->for($this->ecole)->create(['klassci_token' => null]);
    }

    /**
     * Une séance dont `$enseignant` est l'auteur, dans l'état visio demandé.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function seanceDe(User $enseignant, string $etat, array $attributs = []): Seance
    {
        return Seance::factory()->for($this->ecole)->{$etat}()->create($attributs + [
            'created_by' => $enseignant->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);
    }

    private static function url(int $seanceId, string $geste): string
    {
        return "/api/lms/seances/{$seanceId}/{$geste}-visio";
    }

    private static function chemin(string $geste): string
    {
        return "/lms/seances/{seanceId}/{$geste}-visio";
    }
}
