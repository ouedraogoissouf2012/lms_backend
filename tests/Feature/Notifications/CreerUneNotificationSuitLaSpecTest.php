<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\InstitutionMode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1519 : `POST /api/admin/notifications/create` suit `docs/openapi.yaml`, réponse par réponse.
 *
 * Vrai jeton (`asTenant`) : le tenant est résolu comme en production. L'autorisation est jugée
 * AVANT la validation : pour un coordinateur, un destinataire inconnu ou d'une autre école rend
 * le MÊME 403, sans révéler l'existence du compte ; ce test le fige.
 */
final class CreerUneNotificationSuitLaSpecTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/admin/notifications/create';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return array<string, array{InstitutionMode}> */
    public static function volets(): array
    {
        return ['école KLASSCI' => [InstitutionMode::Klassci], 'école autonome' => [InstitutionMode::Standalone]];
    }

    #[DataProvider('volets')]
    public function test_le_coordinateur_ecrit_a_un_compte_de_son_ecole(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->postJson('/api'.self::CHEMIN, $this->charge($this->membre($ecole, 'etudiant')));

        $reponse->assertOk();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'post', 'message');
    }

    #[DataProvider('volets')]
    public function test_un_destinataire_d_une_autre_ecole_rend_403(InstitutionMode $mode): void
    {
        $ailleurs = $this->membre($this->ecole($mode), 'etudiant');

        $reponse = $this->asTenant($this->membre($this->ecole($mode), 'coordinateur'))
            ->postJson('/api'.self::CHEMIN, $this->charge($ailleurs));

        $reponse->assertForbidden();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /** L'autorisation précède la validation : un id inconnu n'est pas un 422 pour un coordinateur. */
    public function test_un_destinataire_inconnu_rend_le_meme_403_et_non_un_422(): void
    {
        $coordinateur = $this->membre($this->ecole(), 'coordinateur');
        $inconnu = (int) User::query()->withoutGlobalScope('institution')->max('id') + 1000;

        $reponse = $this->asTenant($coordinateur)
            ->postJson('/api'.self::CHEMIN, ['user_id' => $inconnu, 'title' => 'Titre', 'message' => 'Corps']);

        $reponse->assertForbidden();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_un_titre_manquant_rend_422_avec_le_detail_par_champ(): void
    {
        $ecole = $this->ecole();
        $charge = $this->charge($this->membre($ecole, 'etudiant'));
        unset($charge['title']);

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->postJson('/api'.self::CHEMIN, $charge);

        $reponse->assertUnprocessable()->assertJsonValidationErrors('title')->assertJsonMissingPath('reason');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_une_action_url_hors_chemin_interne_et_https_rend_422(): void
    {
        $ecole = $this->ecole();

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->postJson(
            '/api'.self::CHEMIN,
            [...$this->charge($this->membre($ecole, 'etudiant')), 'action_url' => 'data:text/html,x'],
        );

        $reponse->assertUnprocessable()->assertJsonValidationErrors('action_url');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /**
     * Seul le supradmin existe sans école : un compte d'école ne se crée pas sans elle (#1181).
     * Le destinataire sans école est donc le supradmin lui-même, comme dans #801.
     */
    public function test_le_supradmin_et_un_destinataire_sans_ecole_rendent_le_422_a_motif(): void
    {
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $reponse = $this->asTenant($supradmin)->postJson('/api'.self::CHEMIN, $this->charge($supradmin));

        $reponse->assertUnprocessable()->assertJsonPath('reason', 'recipient_without_institution');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    public function test_sans_jeton_401(): void
    {
        $reponse = $this->postJson('/api'.self::CHEMIN, ['user_id' => 1, 'title' => 'Titre', 'message' => 'Corps']);

        $reponse->assertUnauthorized();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
    }

    /** @return array{user_id: int, title: string, message: string, type: string} */
    private function charge(User $destinataire): array
    {
        return ['user_id' => (int) $destinataire->getKey(), 'title' => 'Titre', 'message' => 'Corps', 'type' => 'info'];
    }
}
