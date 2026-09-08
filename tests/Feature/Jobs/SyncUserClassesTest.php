<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SyncKlassciClasse;
use App\Jobs\SyncUserClasses;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\Seances\Sync\SyncTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * La synchronisation des classes d'un enseignant, hors du chemin de requête.
 *
 * ## Pourquoi un job et pas un appel direct au login
 *
 * `syncUserClasses()` fait `GET /classes` PUIS un pool `GET classes/{id}` par
 * classe. C'est exactement le travail que {@see SyncKlassciClasse} a
 * été créé pour sortir du chemin critique — son docblock le dit : « du travail
 * destiné à un besoin futur, exécuté sur le chemin critique d'un utilisateur
 * présent ». On ne le remet pas dans le login.
 *
 * ## L'invariant de sécurité du dépôt
 *
 * Le jeton KLASSCI ne voyage JAMAIS dans le payload d'un job : il y serait
 * sérialisé en clair dans la table `jobs`, lisible par quiconque accède à la
 * base. Le job ne transporte que des identifiants et relit le jeton depuis
 * l'utilisateur au moment de s'exécuter — même patron que `SyncKlassciClasse`.
 */
final class SyncUserClassesTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_token' => 'jeton-enseignant',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * L'INVARIANT : aucun jeton dans le payload sérialisé.
     */
    public function test_the_klassci_token_never_travels_in_the_job_payload(): void
    {
        $job = new SyncUserClasses($this->teacher->id, (int) $this->institution->id);

        self::assertStringNotContainsString('jeton-enseignant', serialize($job));
    }

    /**
     * Le jeton est relu depuis l'utilisateur, au moment de l'exécution.
     *
     * `ClasseSyncService` est `final` : Mockery ne peut pas le doubler. On
     * vérifie donc le COMPORTEMENT observable — la classe est bien miroitée, et
     * l'appel a porté le jeton de l'utilisateur — plutôt qu'une interaction.
     * C'est de toute façon la meilleure assertion : elle survivrait à un
     * changement de collaborateur.
     */
    public function test_it_syncs_with_the_token_read_from_the_user(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 5, 'libelle' => 'B2 COM']]], 200)]);

        $this->runJob();

        self::assertSame(
            1,
            Classe::withoutGlobalScopes()->where('klassci_id', 5)->count(),
            'la classe n a pas ete miroitee',
        );

        Http::assertSent(
            static fn ($requete): bool => $requete->hasHeader('Authorization', 'Bearer jeton-enseignant')
        );
    }

    /**
     * Jeton disparu entre la mise en file et l'exécution : ce n'est pas une
     * panne, c'est une course normale. On abandonne sans réessayer.
     */
    public function test_a_missing_token_abandons_without_syncing(): void
    {
        $this->teacher->forceFill(['klassci_token' => null])->save();
        Http::fake();

        $this->runJob();

        Http::assertNothingSent();
    }

    /**
     * Institution disparue : même raisonnement, et surtout aucune écriture
     * dans un tenant qui n'existe plus.
     */
    public function test_a_vanished_institution_abandons_without_syncing(): void
    {
        Http::fake();

        (new SyncUserClasses($this->teacher->id, 999999))->handle(
            app(ClasseSyncService::class),
            app(SyncTenantContext::class),
            app(LoggerInterface::class),
        );

        Http::assertNothingSent();
    }

    /**
     * La file `low` : ce travail n'est jamais urgent, et il ne doit pas passer
     * devant les notifications visio.
     */
    public function test_it_lands_on_the_low_priority_queue(): void
    {
        self::assertSame('low', (new SyncUserClasses(1, 1))->queue);
    }

    private function runJob(): void
    {
        (new SyncUserClasses($this->teacher->id, (int) $this->institution->id))->handle(
            app(ClasseSyncService::class),
            app(SyncTenantContext::class),
            app(LoggerInterface::class),
        );
    }
}
