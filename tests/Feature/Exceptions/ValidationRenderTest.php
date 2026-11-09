<?php

declare(strict_types=1);

namespace Tests\Feature\Exceptions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Une saisie invalide sur une route `api/*` rend le 422 JSON, que la requête
 * annonce `Accept: application/json` ou non.
 *
 * L'API est sans état : comme le 401 (#244) et le 503, le 422 suit la route et
 * non l'en-tête. Sans cela, un client hors navigateur (curl, script,
 * intégration) recevait une redirection HTML au lieu des erreurs de champ. Le
 * rendu vit dans `bootstrap/app.php` : c'est là que Laravel le lit.
 *
 * Une route web garde le comportement de Laravel, la redirection.
 *
 * @see bootstrap/app.php withExceptions()
 */
final class ValidationRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_validation-web', fn (Request $requete) => $requete->validate([
            'champ' => 'required',
        ]));
    }

    public function test_une_route_api_sans_accept_json_rend_le_422_json(): void
    {
        $this->assertLe422DeValidation($this->post('/api/auth/login'));
    }

    public function test_une_route_api_avec_accept_json_rend_le_meme_422(): void
    {
        $this->assertLe422DeValidation($this->postJson('/api/auth/login'));
    }

    public function test_une_route_web_garde_la_redirection_de_laravel(): void
    {
        $this->get('/_validation-web')->assertRedirect();
    }

    private function assertLe422DeValidation(TestResponse $reponse): void
    {
        $reponse->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonStructure(['errors' => ['username', 'password']]);
        self::assertSame(['success', 'message', 'errors'], array_keys((array) $reponse->json()));
    }
}
