<?php

declare(strict_types=1);

namespace Tests\Feature\Klassci;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Klassci\Auth\StudentClassSynchronizer;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use App\Services\Visio\Recording\SeanceRecordingAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * #878 — une ligne de `user_classes` écrite pendant le login porte son établissement.
 *
 * ## Le défaut
 *
 * `BelongsToInstitution` pose `institution_id` à la création, depuis le tenant
 * résolu. Mais `StudentClassSynchronizer` tourne **pendant le login**, où
 * `ResolveInstitution` n'a encore rien posé : le trait passe en `log-and-no-op`
 * et la colonne restait nulle.
 *
 * `UserClass` porte pourtant ce même scope, qui filtre précisément dessus. Les
 * **cinq** lecteurs de la table étaient donc aveugles — y compris ceux qui ne
 * filtrent rien eux-mêmes.
 *
 * ## Pourquoi ces tests RÉSOLVENT un tenant
 *
 * C'est la faute qui a masqué ce défaut dans la PR #877 : sans tenant résolu, le
 * scope global se désactive et **le test ne prouve rien**. Mesuré alors :
 * `canRead` rendait `true` sans tenant et `false` avec — c'est-à-dire faux en
 * production, pendant que le test passait au vert.
 *
 * Chaque cas ci-dessous pose donc explicitement le tenant, comme le fait
 * `ResolveInstitution` sur une requête réelle.
 *
 * @see docs/adr/2026-09-21-878-01-institution-du-miroir-de-classe.md
 */
final class UserClassInstitutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_synchronisation_du_login_pose_l_etablissement(): void
    {
        $ecole = Institution::factory()->create();
        $eleve = User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'role' => 'etudiant',
        ]);

        // Le login ne résout AUCUN tenant : c'est tout le sujet.
        app(TenantManager::class)->reset();

        $this->synchroniser($eleve, 44);

        self::assertSame(
            $ecole->getKey(),
            DB::table('user_classes')->where('user_id', $eleve->id)->value('institution_id'),
            'Écrite pendant le login, la ligne doit porter l\'établissement de son utilisateur.'
        );
    }

    public function test_la_ligne_est_VISIBLE_sous_le_scope_du_tenant(): void
    {
        // La conséquence qui compte : une ligne sans établissement est invisible
        // à tous les lecteurs, le scope global suffisant à la masquer.
        $ecole = Institution::factory()->create();
        $eleve = User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'role' => 'etudiant',
        ]);

        app(TenantManager::class)->reset();
        $this->synchroniser($eleve, 44);

        app(TenantManager::class)->set($ecole);

        self::assertSame(
            1,
            UserClass::query()->where('user_id', $eleve->id)->count(),
            'Sous le scope du tenant, la ligne doit être vue.'
        );
    }

    public function test_la_porte_du_replay_fonctionne_AVEC_un_tenant_resolu(): void
    {
        // Le correctif de la PR #877 ne marchait PAS en production : mesuré,
        // `canRead` rendait true sans tenant et false avec. Ce test l'exerce
        // dans l'état de la production.
        $ecole = Institution::factory()->create();
        $eleve = User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'role' => 'etudiant',
            'klassci_id' => null,
            'klassci_enseignant_id' => null,
        ]);

        app(TenantManager::class)->reset();
        $this->synchroniser($eleve, 77);

        $seance = Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => null,
            'klassci_classe_id' => 77,
            'klassci_enseignant_id' => null,
        ]);

        app(TenantManager::class)->set($ecole);

        self::assertTrue(
            app(SeanceRecordingAccessService::class)->canRead($seance, $eleve),
            'AVEC un tenant résolu — l\'état de la production — la porte doit s\'ouvrir.'
        );
    }

    public function test_un_compte_sans_etablissement_laisse_la_colonne_nulle(): void
    {
        // On ne devine pas : un compte hors établissement — un supradmin — ne
        // fournit aucune valeur à recopier.
        $orphelin = User::factory()->create([
            'institution_id' => null,
            'role' => 'etudiant',
        ]);

        app(TenantManager::class)->reset();
        $this->synchroniser($orphelin, 44);

        self::assertNull(
            DB::table('user_classes')->where('user_id', $orphelin->id)->value('institution_id')
        );
    }

    private function synchroniser(User $user, int $klassciClasseId): void
    {
        $proxy = Mockery::mock(KlassciProxyService::class);
        $proxy->shouldReceive('requestWithUserToken')
            ->andReturn(['data' => ['classe' => ['id' => $klassciClasseId, 'name' => 'Classe '.$klassciClasseId]]]);

        (new StudentClassSynchronizer($proxy, new NullLogger))->sync($user, 'jeton-klassci');
    }
}
