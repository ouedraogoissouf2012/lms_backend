<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\InstitutionMode;
use App\Http\Middleware\EnsureSchoolMemberOrPlatformRoute;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * D499 / D502 : deux gardes de route, une par prédicat déjà écrit dans le code.
 *
 * - `gestion` délègue à `User::isManager()` (coordinateur ou `isAdmin()`) ;
 * - `personnel` délègue à `User::isStaff()` (enseignant, coordinateur ou `isAdmin()`).
 *
 * Avant, chaque groupe recopiait sa liste `role:`, et `admin` (rang 4, au-dessus du
 * coordinateur) recevait 403 là où le coordinateur passait : `/admin/users`, le CRUD
 * `users`, `admin/analytics`, les rapports, l'historique et les présences des séances.
 *
 * Ce que ce test juge, c'est la GARDE : un compte admis ne reçoit pas le 403 de la
 * garde, et l'élève le reçoit. Le corps de chaque route est éprouvé par ses propres
 * tests. Une réponse 5xx échoue ici aussi : admettre un compte ne doit rien casser.
 *
 * Vrai jeton, les deux types d'école.
 */
final class GardesGestionEtPersonnelTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private Institution $ecole;

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function ecoles(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('ecoles')]
    public function test_admin_entre_dans_la_gestion(InstitutionMode $mode): void
    {
        $admin = $this->compte($mode, 'admin');

        foreach ($this->routesDeGestion() as $nom => [$methode, $uri]) {
            $this->assertAdmis($this->appeler($admin, $methode, $uri), "admin, $nom");
        }
    }

    #[DataProvider('ecoles')]
    public function test_admin_entre_dans_les_routes_du_personnel(InstitutionMode $mode): void
    {
        $admin = $this->compte($mode, 'admin');

        foreach ($this->routesDuPersonnel($admin) as $nom => [$methode, $uri]) {
            $this->assertAdmis($this->appeler($admin, $methode, $uri), "admin, $nom");
        }
    }

    /** Témoin : le coordinateur passe partout, comme avant. */
    #[DataProvider('ecoles')]
    public function test_le_coordinateur_passe_partout(InstitutionMode $mode): void
    {
        $coordinateur = $this->compte($mode, 'coordinateur');

        foreach ([...$this->routesDeGestion(), ...$this->routesDuPersonnel($coordinateur)] as $nom => [$methode, $uri]) {
            $this->assertAdmis($this->appeler($coordinateur, $methode, $uri), "coordinateur, $nom");
        }
    }

    /** Témoin : l'enseignant passe les routes du personnel, et reste hors de la gestion. */
    #[DataProvider('ecoles')]
    public function test_l_enseignant_passe_le_personnel_et_pas_la_gestion(InstitutionMode $mode): void
    {
        $enseignant = $this->compte($mode, 'enseignant');

        foreach ($this->routesDuPersonnel($enseignant) as $nom => [$methode, $uri]) {
            $this->assertAdmis($this->appeler($enseignant, $methode, $uri), "enseignant, $nom");
        }
        foreach ($this->routesDeGestion() as $nom => [$methode, $uri]) {
            $this->appeler($enseignant, $methode, $uri)->assertForbidden();
        }
    }

    /** Témoin : l'élève est refusé partout. */
    #[DataProvider('ecoles')]
    public function test_l_eleve_est_refuse_partout(InstitutionMode $mode): void
    {
        $eleve = $this->compte($mode, 'etudiant');

        foreach ([...$this->routesDeGestion(), ...$this->routesDuPersonnel($eleve)] as $nom => [$methode, $uri]) {
            self::assertSame(403, $this->appeler($eleve, $methode, $uri)->status(), "élève, $nom");
        }
    }

    /**
     * Témoin : le superAdmin passe partout, AVANT comme après. `EnsureRole` le laissait
     * déjà franchir toute liste `role:` qui ne nomme pas `supradmin` (`userHasRole`), et
     * `isManager()` / `isStaff()` l'admettent par `isAdmin()`. Aucun changement pour lui.
     */
    #[DataProvider('ecoles')]
    public function test_le_superadmin_passe_partout_comme_avant(InstitutionMode $mode): void
    {
        $superAdmin = $this->compte($mode, 'superAdmin');

        foreach ([...$this->routesDeGestion(), ...$this->routesDuPersonnel($superAdmin)] as $nom => [$methode, $uri]) {
            $this->assertAdmis($this->appeler($superAdmin, $methode, $uri), "superAdmin, $nom");
        }
    }

    /**
     * Le supradmin plateforme : `isAdmin()` vaut vrai pour lui, donc les deux gardes
     * l'admettraient. Il est arrêté AVANT elles, par `EnsureSchoolMemberOrPlatformRoute`,
     * placé juste après l'authentification : aucune de ces routes n'est « plateforme ».
     */
    public function test_le_supradmin_s_arrete_a_la_porte_des_etablissements(): void
    {
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole(InstitutionMode::Standalone);
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        foreach ([...$this->routesDeGestion(), ...$this->routesDuPersonnel($supradmin)] as $nom => [$methode, $uri]) {
            $this->appeler($supradmin, $methode, $uri)
                ->assertForbidden()
                ->assertJsonPath('message', EnsureSchoolMemberOrPlatformRoute::MESSAGE);
        }
    }

    /**
     * D502 : créer un chapitre. La route admet déjà `admin`
     * (`role:enseignant,coordinateur,admin`), mais la FormRequest ne laissait passer que
     * l'enseignant et le coordinateur : l'admin recevait 403. Le corps est vide : la
     * garde est jugée avant la validation, un 422 dit qu'elle a admis.
     *
     * Créer une LEÇON ne relève plus de D502 : seul l'enseignant la crée (#1167,
     * `Tests\Feature\Lesson\SeulLEnseignantCreeUneLeconTest`).
     */
    #[DataProvider('ecoles')]
    public function test_admin_cree_un_chapitre(InstitutionMode $mode): void
    {
        foreach (['admin', 'superAdmin', 'coordinateur', 'enseignant'] as $role) {
            $compte = $this->compte($mode, $role);

            $this->assertAdmis($this->asTenant($compte)->postJson("/api/lessons/{$this->leconDeLEcole()->getKey()}/chapters", []), "$role, créer un chapitre");
        }

        $eleve = $this->compte($mode, 'etudiant');
        $this->asTenant($eleve)->postJson("/api/lessons/{$this->leconDeLEcole()->getKey()}/chapters", [])->assertForbidden();
    }

    private function leconDeLEcole(): Lesson
    {
        $classe = $this->classe($this->ecole, '6e A');

        return Lesson::factory()->create(['institution_id' => $this->ecole->getKey(), 'classe_id' => $classe->getKey()]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function routesDeGestion(): array
    {
        return [
            'la liste des comptes' => ['GET', '/api/admin/users'],
            'les analyses' => ['GET', '/api/admin/analytics/system-metrics'],
            'créer un compte' => ['POST', '/api/users'],
            'le rapport de notes' => ['POST', '/api/admin/reports/grades'],
            'les statistiques (témoin, déjà admis)' => ['GET', '/api/admin/statistics'],
            'le tableau de gestion (témoin, déjà admis)' => ['GET', '/api/dashboard/stats'],
        ];
    }

    /**
     * La séance appartient au compte testé : le contrôleur des présences refuse de
     * lui-même un enseignant qui n'en est pas l'auteur, et ce test ne juge que la garde.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function routesDuPersonnel(User $proprietaire): array
    {
        $seance = Seance::factory()->for($this->ecole)->create([
            'klassci_seance_id' => null, 'klassci_classe_id' => null, 'klassci_enseignant_id' => null,
            'created_by' => $proprietaire->id,
        ]);

        return [
            'l historique des séances' => ['GET', '/api/lms/seances/history'],
            'les présences d une séance' => ['GET', '/api/lms/seances/'.$seance->id.'/attendances'],
        ];
    }

    private function compte(InstitutionMode $mode, string $role): User
    {
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole($mode);

        return $this->membre($this->ecole, $role);
    }

    /** @return TestResponse<Response> */
    private function appeler(User $compte, string $methode, string $uri): TestResponse
    {
        return $this->asTenant($compte)->json($methode, $uri);
    }

    /** @param  TestResponse<Response>  $reponse */
    private function assertAdmis(TestResponse $reponse, string $cas): void
    {
        self::assertNotSame(403, $reponse->status(), "$cas : la garde refuse");
        self::assertNotSame(401, $reponse->status(), "$cas : non authentifié");
        self::assertLessThan(500, $reponse->status(), "$cas : la route casse ({$reponse->status()})");
    }
}
