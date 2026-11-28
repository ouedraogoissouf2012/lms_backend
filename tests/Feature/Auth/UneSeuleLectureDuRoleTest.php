<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Middleware\EnsurePermission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #949 — une seule lecture du rôle : la garde de route `role:` et les services
 * (`User::isTeacher()`…) disent la MÊME chose de chaque valeur de `users.role`.
 *
 * Avant, `EnsureRole` comparait `strtolower(trim())` : `Enseignant` passait la
 * garde, mais n'était rien pour les services, et une variante de `superAdmin`
 * (` superAdmin `, `SUPERADMIN`) ouvrait TOUTES les gardes `role:`. La mesure en
 * production (17/11) : aucune variante en base, personne ne perd d'accès.
 *
 * Les deux types d'école : la garde leur est commune.
 */
final class UneSeuleLectureDuRoleTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /**
     * Chaque valeur, et ce qu'en dit la garde `permission:visio.enregistrements` (l'ancienne `role:enseignant`,
     * migration déclarée #1665) : le service la
     * reconnaît comme enseignant, ou comme `superAdmin` (qui passe toute garde
     * sauf celles du supradmin) — et rien d'autre ne passe.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function valeurs(): array
    {
        return [
            'enseignant' => ['enseignant', true],
            'alias anglais' => ['teacher', true],
            'superAdmin' => ['superAdmin', true],
            'variante de casse' => ['Enseignant', false],
            'majuscules' => ['ENSEIGNANT', false],
            'entouré d\'espaces' => [' enseignant ', false],
            'superAdmin, casse' => ['SuperAdmin', false],
            'superAdmin, majuscules' => ['SUPERADMIN', false],
            'superAdmin, espaces' => [' superAdmin ', false],
            'ancien alias retiré' => ['administrator', false],
            'coordinateur' => ['coordinateur', false],
            'rôle inconnu' => ['directeur', false],
        ];
    }

    #[DataProvider('valeurs')]
    public function test_la_garde_et_le_service_lisent_le_role_de_la_meme_facon(string $role, bool $admis): void
    {
        $compte = $this->compte(InstitutionMode::Standalone, $role);
        $request = Request::create('/api/garde-949', 'GET');
        $request->setUserResolver(static fn () => $compte);

        $statut = $this->app->make(EnsurePermission::class)->handle($request, static fn () => response('ok'), Permission::VisioEnregistrements->value)->getStatusCode();

        self::assertSame($admis ? 200 : 403, $statut, "garde permission:visio.enregistrements, rôle « $role »");
        self::assertSame($admis, $compte->isTeacher() || Role::tryFromString($compte->role) === Role::SuperAdmin, "service, rôle « $role »");
    }

    /** @return array<string, array{0: InstitutionMode, 1: string, 2: bool}> */
    public static function voletsEtEleves(): array
    {
        $cas = [];
        foreach (['école du LMS' => InstitutionMode::Standalone, 'école KLASSCI' => InstitutionMode::Klassci] as $volet => $mode) {
            $cas["$volet, etudiant"] = [$mode, 'etudiant', true];
            $cas["$volet, alias student"] = [$mode, 'student', true];
            $cas["$volet, Etudiant"] = [$mode, 'Etudiant', false];
            $cas["$volet, espaces"] = [$mode, ' etudiant ', false];
            $cas["$volet, SuperAdmin"] = [$mode, 'SuperAdmin', false];
        }

        return $cas;
    }

    /**
     * Une vraie route gardée `role:etudiant` (`POST /me/inscriptions`), vrai jeton.
     * Le corps est vide : admis, le compte reçoit le 422 de la validation ; refusé,
     * le 403 de la garde.
     */
    #[DataProvider('voletsEtEleves')]
    public function test_une_route_gardee_suit_la_meme_lecture_dans_les_deux_volets(InstitutionMode $mode, string $role, bool $admis): void
    {
        $this->disableKlassciMiddleware();
        $compte = $this->compte($mode, $role);

        $statut = $this->asTenant($compte)->postJson('/api/me/inscriptions', [])->getStatusCode();

        if ($admis) {
            self::assertSame(422, $statut, "rôle « $role » : la garde doit l'admettre");
        } else {
            self::assertSame(403, $statut, "rôle « $role » : la garde doit le refuser");
        }
    }

    private function compte(InstitutionMode $mode, string $role): User
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return User::factory()->for($ecole)->create(['role' => $role, 'last_klassci_sync' => now()]);
    }
}
