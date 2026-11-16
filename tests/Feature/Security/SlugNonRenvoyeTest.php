<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1129 — le 400 de `ResolveInstitution` ne recopie pas l'en-tête reçu.
 *
 * Une requête sans jeton, dont l'en-tête `X-Institution` désigne un
 * établissement inconnu ou inactif, est refusée avant toute route, avant même
 * le 401 de l'authentification. Le message renvoyait la valeur fournie, telle
 * quelle et sans borne : une réflexion d'entrée (PRODUCTION_STANDARDS §1.2).
 *
 * Le refus ne distingue pas non plus un slug inconnu d'un slug inactif : les
 * séparer dirait à un inconnu quels établissements existent. Les deux volets
 * d'école reçoivent donc le même corps, au caractère près.
 */
final class SlugNonRenvoyeTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/api/lessons/my-courses';

    private const CORPS = ['success' => false, 'message' => 'Établissement introuvable ou inactif.'];

    public function test_un_slug_inconnu_n_est_pas_recopie(): void
    {
        $envoye = '<b>slug-'.str_repeat('x', 200).'</b>';

        $reponse = $this->getJson(self::ROUTE, ['X-Institution' => $envoye])->assertStatus(400);

        $reponse->assertExactJson(self::CORPS);
        self::assertStringNotContainsString('slug-xxxx', (string) $reponse->getContent());
    }

    /**
     * @return array<string, array{InstitutionMode}>
     */
    public static function volets(): array
    {
        return [
            'école KLASSCI' => [InstitutionMode::Klassci],
            'école autonome' => [InstitutionMode::Standalone],
        ];
    }

    #[DataProvider('volets')]
    public function test_le_slug_d_une_ecole_inactive_recoit_le_meme_corps(InstitutionMode $mode): void
    {
        $factory = Institution::factory();
        $ecole = ($mode === InstitutionMode::Standalone ? $factory->standalone() : $factory)
            ->create(['mode' => $mode, 'is_active' => false]);

        $reponse = $this->getJson(self::ROUTE, ['X-Institution' => $ecole->slug])->assertStatus(400);

        $reponse->assertExactJson(self::CORPS);
        self::assertStringNotContainsString($ecole->slug, (string) $reponse->getContent());
    }
}
