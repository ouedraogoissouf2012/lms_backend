<?php

declare(strict_types=1);

namespace Tests\Feature\Exceptions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $valider = fn (Request $requete): array => $requete->validate(['champ' => 'required']);
        Route::middleware('api')->post('/api/_validation-api', $valider);
        Route::middleware('web')->get('/_validation-web', $valider);
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function entetesSansJson(): iterable
    {
        yield 'sans en-tête Accept' => [[]];
        yield 'Accept d\'un export PDF' => [['Accept' => 'application/pdf']];
        yield 'Accept HTML' => [['Accept' => 'text/html']];
    }

    /** @param  array<string, string>  $entetes */
    #[DataProvider('entetesSansJson')]
    public function test_une_route_api_sans_accept_json_rend_le_422_json(array $entetes): void
    {
        $this->assertLe422DeValidation($this->post('/api/_validation-api', [], $entetes));
    }

    public function test_une_route_api_avec_accept_json_rend_le_meme_422(): void
    {
        $this->assertLe422DeValidation($this->postJson('/api/_validation-api'));
    }

    /** Le chemin réel : une FormRequest d'une route métier. */
    public function test_une_formrequest_reelle_sans_accept_json_rend_le_422_json(): void
    {
        $this->post('/api/auth/login')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed');
    }

    public function test_une_route_web_garde_la_redirection_de_validation_de_laravel(): void
    {
        $this->get('/_validation-web')->assertRedirect()->assertSessionHasErrors('champ');
    }

    private function assertLe422DeValidation(TestResponse $reponse): void
    {
        $reponse->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonStructure(['errors' => ['champ']]);
        self::assertSame(['success', 'message', 'errors'], array_keys((array) $reponse->json()));
    }
}
