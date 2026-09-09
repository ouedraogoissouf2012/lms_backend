<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Jobs\SyncUserClasses;
use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Le login ne doit porter AUCUNE synchronisation KLASSCI (#712).
 *
 * ## Pourquoi ce garde existe
 *
 * Deux tests vivaient ici — « le login enregistre le lien matière » et « le
 * login déclenche la synchro des classes ». Ils étaient VERTS, et le
 * comportement qu'ils décrivaient ne pouvait pas fonctionner en production.
 *
 * `KlassciConfigResolver` résout l'URL amont en trois priorités : le jeton
 * personnel de l'utilisateur AUTHENTIFIÉ, son institution, puis la
 * configuration globale. Pendant le login, aucun utilisateur Sanctum n'existe
 * encore : les deux premières sont hors d'atteinte, et la troisième lit une
 * config globale qui n'a pas de sens en multi-tenant — chaque établissement a
 * son propre serveur KLASSCI, renseigné en base.
 *
 * Les tests passaient parce qu'ils POSAIENT `services.klassci.url` dans leur
 * `setUp()`. La production, elle, ne l'a pas — mesure du 2026-09-09, à chaque
 * reconnexion :
 *
 *     production.ERROR: Erreur sync matières au login
 *       error: "URL de base KLASSCI absente ou invalide"
 *
 * Le `catch` avalait l'exception, et tout ce qui suivait dans le `try` — le
 * lien ET le dispatch — n'était jamais atteint.
 *
 * ## Ce que ce test empêche
 *
 * Qu'on rebranche une synchronisation KLASSCI sur le login parce que « ça
 * semble être le bon endroit ». Ce n'en est pas un, et le prochain à essayer
 * aura des tests verts et une production muette.
 *
 * Le déclencheur vit sur un chemin AUTHENTIFIÉ :
 * `MyMatieresQueryService::getMatieresForUser()`, vérifié par
 * tests/Feature/Matiere/MyMatieresRecordsTeacherLinkTest.php.
 */
final class LoginCarriesNoKlassciSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // On NE pose PAS `services.klassci.url` : c'est l'état réel de la
        // production multi-tenant, et c'est précisément ce que les anciens
        // tests masquaient.
        Http::fake(['*' => Http::response(['data' => []], 200)]);
    }

    public function test_a_teacher_login_dispatches_no_klassci_sync_job(): void
    {
        Queue::fake();

        $this->login('enseignant');

        Queue::assertNotPushed(SyncUserClasses::class);
    }

    /**
     * Et surtout : le login ne PROMET rien qu'il ne puisse tenir. Aucun lien
     * écrit, donc aucun écran qui s'appuierait dessus ne sera trompé.
     */
    public function test_a_teacher_login_writes_no_teacher_matiere_link(): void
    {
        $this->login('enseignant');

        self::assertSame(0, DB::table('matiere_enseignant')->count());
    }

    /**
     * Le login reste fonctionnel : l'échec de synchronisation est journalisé,
     * jamais propagé. Un enseignant doit pouvoir se connecter même si KLASSCI
     * est injoignable.
     */
    public function test_the_login_still_succeeds(): void
    {
        $user = $this->loginAndReturnUser('enseignant');

        self::assertSame('enseignant', $user->role);
        self::assertNotNull($user->id);
    }

    private function login(string $role): Institution
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        app(KlassciUserSynchronizer::class)->sync(
            ['id' => 9, 'nom' => 'PROF BEDE', 'email' => $role.'@school.edu', 'role' => $role],
            'teacher-token',
            'https://school-a.klassci.test',
            $institution,
        );

        return $institution;
    }

    private function loginAndReturnUser(string $role): User
    {
        $institution = Institution::factory()->create(['slug' => 'school-b']);

        return app(KlassciUserSynchronizer::class)->sync(
            ['id' => 9, 'nom' => 'PROF BEDE', 'email' => $role.'@school.edu', 'role' => $role],
            'teacher-token',
            'https://school-b.klassci.test',
            $institution,
        );
    }
}
