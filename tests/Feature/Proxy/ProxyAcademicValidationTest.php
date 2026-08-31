<?php

declare(strict_types=1);

namespace Tests\Feature\Proxy;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Les routes d'écriture du proxy doivent rejeter une saisie invalide en 422.
 *
 * Défaut corrigé : `$request->validate()` était appelé À L'INTÉRIEUR du `try`, donc
 * la `ValidationException` était capturée par le `catch (\Exception)` et traduite en
 * 500 générique. Le gestionnaire global — seul à savoir rendre le 422 avec le détail
 * par champ — n'était jamais atteint : le client perdait `errors`, et une simple
 * faute de saisie se présentait comme une panne serveur.
 */
final class ProxyAcademicValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsKlassciUser(): User
    {
        /** @var User $user */
        // Ces routes d'ecriture exigent role:enseignant,coordinateur (routes/api/core.php:101).
        $user = User::factory()->create([
            'klassci_token' => 'jeton-perso-de-test',
            'role' => 'enseignant',
        ]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_invalid_notes_payload_is_rejected_as_422_with_field_errors(): void
    {
        Http::fake();
        $this->actingAsKlassciUser();

        $response = $this->postJson('/api/proxy/evaluations/1/notes', ['notes' => []]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('notes');

        // Une entrée invalide ne doit jamais atteindre KLASSCI.
        Http::assertNothingSent();
    }

    public function test_missing_presence_date_is_rejected_as_422(): void
    {
        Http::fake();
        $this->actingAsKlassciUser();

        $response = $this->postJson('/api/proxy/cours/1/presences', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('date_cours');
        Http::assertNothingSent();
    }

    public function test_unknown_course_status_is_rejected_as_422(): void
    {
        Http::fake();
        $this->actingAsKlassciUser();

        $response = $this->putJson('/api/proxy/cours/1/statut', ['statut' => 'nimportequoi']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('statut');
        Http::assertNothingSent();
    }

    /**
     * Le 500 générique reste réservé aux défaillances réelles : une saisie invalide
     * ne doit plus jamais l'emprunter.
     */
    public function test_validation_failure_is_never_reported_as_a_server_error(): void
    {
        Http::fake();
        $this->actingAsKlassciUser();

        $response = $this->postJson('/api/proxy/evaluations/1/notes', ['notes' => []]);

        $response->assertStatus(422);
        self::assertNotSame(
            'Service indisponible. Veuillez réessayer.',
            $response->json('message'),
        );
    }
}
