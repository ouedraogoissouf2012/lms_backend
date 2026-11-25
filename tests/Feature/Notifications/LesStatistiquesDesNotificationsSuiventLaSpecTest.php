<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1570 : `GET /api/admin/notifications/stats` suit `docs/openapi.yaml`, dans ses deux portées
 * (l'école de l'appelant ; toutes les écoles pour le supradmin). Vrai jeton (`asTenant`).
 *
 * Une notification est créée dans chaque cas : `by_type` n'est pas vide, et le schéma de ses
 * lignes (`count` ENTIER, y compris sous MySQL) est donc réellement jugé.
 */
final class LesStatistiquesDesNotificationsSuiventLaSpecTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/admin/notifications/stats';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        // Les compteurs sont mis en cache par portée : une valeur d'un autre test ne doit pas
        // répondre à la place de celle-ci.
        Cache::flush();
    }

    /** @return array<string, array{InstitutionMode}> */
    public static function volets(): array
    {
        return ['école KLASSCI' => [InstitutionMode::Klassci], 'école autonome' => [InstitutionMode::Standalone]];
    }

    #[DataProvider('volets')]
    public function test_le_coordinateur_lit_les_compteurs_de_son_ecole(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $this->notification($ecole);

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson('/api'.self::CHEMIN);

        $reponse->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.by_type.0.count', 1);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
        $this->assertOpenApiSchemaRefuseSans($reponse, self::CHEMIN, 'get', 'data');
    }

    public function test_le_supradmin_lit_les_compteurs_de_toutes_les_ecoles_sous_la_meme_forme(): void
    {
        $this->notification($this->ecole());
        $this->notification($this->ecole(InstitutionMode::Klassci));
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $reponse = $this->asTenant($supradmin)->getJson('/api'.self::CHEMIN);

        $reponse->assertOk()->assertJsonPath('data.total', 2);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    public function test_un_enseignant_rend_403(): void
    {
        $reponse = $this->asTenant($this->membre($this->ecole(), 'enseignant'))->getJson('/api'.self::CHEMIN);

        $reponse->assertForbidden();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    public function test_sans_jeton_401(): void
    {
        $reponse = $this->getJson('/api'.self::CHEMIN);

        $reponse->assertUnauthorized();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    private function notification(Institution $ecole): void
    {
        Notification::factory()->create([
            'institution_id' => $ecole->getKey(),
            'user_id' => $this->membre($ecole, 'etudiant')->getKey(),
            'type' => 'info',
        ]);
    }
}
