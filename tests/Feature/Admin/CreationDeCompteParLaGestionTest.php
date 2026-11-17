<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1181 — `POST /api/users` (`AdminController::createUser`) pose l'établissement de
 * l'ACTEUR, explicitement.
 *
 * Avant, il ne l'écrivait pas : il dépendait du hook de `BelongsToInstitution` et
 * d'un tenant résolu. Sûr par ses appelants, mais pas par lui-même : sans tenant,
 * le compte naissait sans école. Et un acteur sans établissement doit recevoir un
 * refus PROPRE, jamais un 500.
 *
 * Les deux types d'école.
 */
final class CreationDeCompteParLaGestionTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    /** Vrai jeton : le compte créé est dans l'école de la gestion. */
    #[DataProvider('volets')]
    public function test_le_compte_cree_est_dans_l_ecole_de_la_gestion(InstitutionMode $mode): void
    {
        $gestion = $this->gestion($mode);

        $this->asTenant($gestion)->postJson('/api/users', $this->nouveauCompte('vrai.jeton'))->assertCreated();

        self::assertSame($gestion->institution_id, $this->institutionDe('vrai.jeton'));
    }

    /**
     * Sans tenant résolu (aucun jeton porteur) : l'écrivain pose l'école de l'acteur
     * lui-même, sans dépendre du hook.
     */
    #[DataProvider('volets')]
    public function test_sans_tenant_l_ecrivain_pose_l_ecole_de_l_acteur(InstitutionMode $mode): void
    {
        $gestion = $this->gestion($mode);
        Sanctum::actingAs($gestion);

        $this->postJson('/api/users', $this->nouveauCompte('sans.tenant'))->assertCreated();

        self::assertSame($gestion->institution_id, $this->institutionDe('sans.tenant'));
    }

    /** Un acteur sans établissement : un refus propre (403), aucune ligne, jamais un 500. */
    public function test_un_acteur_sans_etablissement_recoit_un_refus_propre(): void
    {
        $supradmin = User::factory()->create(['role' => 'supradmin', 'institution_id' => null]);

        $reponse = $this->asTenant($supradmin)->postJson('/api/users', $this->nouveauCompte('sans.ecole'));

        self::assertSame(403, $reponse->getStatusCode(), (string) $reponse->getContent());
        self::assertSame('no_institution', $reponse->json('reason'));
        self::assertNull($this->institutionDe('sans.ecole'), 'Aucune ligne.');
        self::assertFalse(User::withoutGlobalScopes()->where('email', 'sans.ecole@ecole.test')->exists());
    }

    private function gestion(InstitutionMode $mode): User
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return User::factory()->for($ecole)->create(['role' => 'coordinateur', 'last_klassci_sync' => now()]);
    }

    /** @return array<string, string> */
    private function nouveauCompte(string $nom): array
    {
        return [
            'name' => 'Compte '.$nom,
            'email' => $nom.'@ecole.test',
            'password' => 'mot-de-passe-1181',
            'password_confirmation' => 'mot-de-passe-1181',
            'role' => 'enseignant',
        ];
    }

    private function institutionDe(string $nom): mixed
    {
        return User::withoutGlobalScopes()->where('email', $nom.'@ecole.test')->value('institution_id');
    }
}
