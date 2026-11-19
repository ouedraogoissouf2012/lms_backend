<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\InstitutionMode;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1275 — l'administration d'une école définit le mot de passe d'un compte de son école
 * (`PUT /api/users/{user}/password`).
 *
 * Avant, aucune voie ne le permettait : la branche `password` de `updateUser` était morte, et
 * aucun courriel de réinitialisation ne part (#808). Un compte d'école autonome qui avait oublié
 * son mot de passe n'avait aucun recours.
 *
 * - Seuls l'admin et le superAdmin de l'école, sur un compte de rang inférieur : définir le mot
 *   de passe d'un compte, c'est pouvoir se connecter à sa place. Le coordinateur est refusé.
 * - Les jetons du compte sont révoqués ; le mot de passe n'apparaît ni en réponse ni à l'audit.
 * - École KLASSCI : refus motivé. La connexion essaie le local d'abord : un mot de passe local
 *   ouvrirait une seconde porte, à côté de KLASSCI.
 */
final class DefinirLeMotDePasseTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ANCIEN = 'ancien-mot-de-passe';

    private const NOUVEAU = 'Nouveau-mot-1275';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: string}> */
    public static function administrations(): iterable
    {
        yield 'admin' => ['admin'];
        yield 'superAdmin' => ['superAdmin'];
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('administrations')]
    public function test_l_administration_definit_le_mot_de_passe_et_la_connexion_suit(string $role): void
    {
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $cible = $this->compte($ecole, 'enseignant', 'prof@ecole.test');

        $this->definir($this->compte($ecole, $role, 'gestion@ecole.test'), $cible)->assertOk();

        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['username' => 'prof@ecole.test', 'password' => self::NOUVEAU])->assertOk();
        // L'ancien ne correspond plus. Sa connexion échouée retomberait sur KLASSCI, injoignable
        // en test : on lit le hachage, pas le statut d'une seconde connexion.
        self::assertFalse(Hash::check(self::ANCIEN, (string) User::withoutGlobalScopes()->whereKey($cible->id)->value('password')));
    }

    public function test_les_jetons_du_compte_sont_revoques(): void
    {
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $cible = $this->compte($ecole, 'enseignant', 'prof@ecole.test');
        $cible->createToken('session-ouverte');

        $this->definir($this->compte($ecole, 'admin', 'gestion@ecole.test'), $cible)->assertOk();

        self::assertSame(0, $cible->tokens()->count());
    }

    public function test_ni_la_reponse_ni_l_audit_ne_portent_le_mot_de_passe(): void
    {
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $cible = $this->compte($ecole, 'enseignant', 'prof@ecole.test');
        $acteur = $this->compte($ecole, 'admin', 'gestion@ecole.test');

        $corps = (string) $this->definir($acteur, $cible)->assertOk()->getContent();

        $hachage = (string) User::withoutGlobalScopes()->whereKey($cible->id)->value('password');
        $trace = AuditLog::query()->withoutGlobalScopes()->where('action', 'password_set_by_manager')->sole();
        self::assertSame($acteur->id, $trace->user_id);
        self::assertSame($cible->id, (int) $trace->auditable_id);
        foreach (['réponse' => $corps, 'audit' => (string) json_encode($trace->getAttributes())] as $lieu => $texte) {
            self::assertStringNotContainsString(self::NOUVEAU, $texte, "Le mot de passe apparaît dans {$lieu}.");
            self::assertStringNotContainsString($hachage, $texte, "Le hachage apparaît dans {$lieu}.");
        }
    }

    public function test_en_ecole_klassci_le_refus_est_motive(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $cible = $this->compte($ecole, 'enseignant', 'prof@klassci.test');

        $this->definir($this->compte($ecole, 'superAdmin', 'gestion@klassci.test'), $cible)
            ->assertForbidden()->assertJsonPath('reason', 'password_managed_by_klassci');

        $this->assertMotDePasseInchange($cible);
    }

    #[DataProvider('volets')]
    public function test_un_coordinateur_est_refuse(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $cible = $this->compte($ecole, 'enseignant', 'prof@ecole.test');

        $this->definir($this->compte($ecole, 'coordinateur', 'coord@ecole.test'), $cible)->assertForbidden();

        $this->assertMotDePasseInchange($cible);
    }

    #[DataProvider('volets')]
    public function test_un_enseignant_est_refuse(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $cible = $this->compte($ecole, 'etudiant', 'eleve@ecole.test');

        $this->definir($this->compte($ecole, 'enseignant', 'prof@ecole.test'), $cible)->assertForbidden();

        $this->assertMotDePasseInchange($cible);
    }

    /** Un rang égal ou supérieur, ou soi-même (#932) : refusé. */
    public function test_un_compte_de_rang_egal_ou_superieur_ou_soi_meme_est_refuse(): void
    {
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $acteur = $this->compte($ecole, 'admin', 'gestion@ecole.test');

        foreach ([$this->compte($ecole, 'admin', 'pair@ecole.test'), $this->compte($ecole, 'superAdmin', 'chef@ecole.test'), $acteur] as $cible) {
            $this->definir($acteur, $cible)->assertForbidden();
            $this->assertMotDePasseInchange($cible);
        }
    }

    public function test_un_compte_d_une_autre_ecole_est_introuvable(): void
    {
        $ailleurs = $this->compte($this->ecole(InstitutionMode::Standalone, 'ailleurs'), 'enseignant', 'prof@ailleurs.test');

        $this->definir($this->compte($this->ecole(InstitutionMode::Standalone), 'admin', 'gestion@ecole.test'), $ailleurs)->assertNotFound();

        $this->assertMotDePasseInchange($ailleurs);
    }

    public function test_un_mot_de_passe_faible_ou_non_confirme_est_refuse(): void
    {
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $cible = $this->compte($ecole, 'enseignant', 'prof@ecole.test');
        $acteur = $this->compte($ecole, 'admin', 'gestion@ecole.test');

        foreach ([['court', 'court'], [self::NOUVEAU, 'autre-chose-1275']] as [$mot, $confirmation]) {
            $this->asTenant($acteur)->putJson("/api/users/{$cible->id}/password", ['password' => $mot, 'password_confirmation' => $confirmation])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }

        $this->assertMotDePasseInchange($cible);
    }

    private function ecole(InstitutionMode $mode, string $slug = 'ecole'): Institution
    {
        return Institution::factory()->create(['mode' => $mode, 'slug' => $slug.'-'.$mode->value]);
    }

    private function compte(Institution $ecole, string $role, string $email): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => $role,
            'email' => $email,
            'password' => Hash::make(self::ANCIEN),
            'last_klassci_sync' => now(),
        ]);
    }

    private function definir(User $acteur, User $cible): TestResponse
    {
        return $this->asTenant($acteur)->putJson("/api/users/{$cible->id}/password", [
            'password' => self::NOUVEAU,
            'password_confirmation' => self::NOUVEAU,
        ]);
    }

    private function assertMotDePasseInchange(User $cible): void
    {
        $hachage = (string) User::withoutGlobalScopes()->whereKey($cible->id)->value('password');

        self::assertTrue(Hash::check(self::ANCIEN, $hachage), 'Le mot de passe a changé.');
    }
}
