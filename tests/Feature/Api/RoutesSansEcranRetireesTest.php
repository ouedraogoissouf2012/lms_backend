<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Deux routes sans écran, retirées (D606, D610). Aucun écran du front ne les
 * appelait, dans aucun des deux volets d'école ; aucun job ni commande non plus.
 *
 * - `POST /evaluations/{id}/sync-notes` ne contactait JAMAIS KLASSCI (stub avoué)
 *   et marquait pourtant les copies `synced_to_klassci` : une fausse confirmation.
 *   La synchronisation des notes est `sync-klassci`, qui appelle vraiment KLASSCI.
 * - `GET /lms/seances/{id}/participants` cherchait, en école KLASSCI, la séance dans
 *   une clé que KLASSCI laisse toujours vide (404 pour toute séance), et rendait
 *   l'appelant comme enseignant. Son seul appelant, le compteur de participants
 *   de la visio, a été retiré du front le 14/11. La liste des élèves d'une séance
 *   reste servie au personnel par `/details` et `/attendances`.
 *
 * Un appel à l'une d'elles rend désormais 404, et n'écrit rien.
 */
final class RoutesSansEcranRetireesTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function routesRetirees(): array
    {
        return [
            'sync-notes' => ['POST', 'evaluations/{id}/sync-notes'],
            'participants' => ['GET', 'lms/seances/{seanceId}/participants'],
        ];
    }

    /** Sous TOUS ses montages : `api/…` et le préfixe versionné (#217). */
    #[DataProvider('routesRetirees')]
    public function test_la_route_n_est_plus_dans_la_table(string $methode, string $fin): void
    {
        $presentes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (RouteDefinition $route): bool => str_ends_with($route->uri(), '/'.$fin) && in_array($methode, $route->methods(), true))
            ->map(static fn (RouteDefinition $route): string => $route->uri())
            ->values()
            ->all();

        self::assertSame([], $presentes, "{$methode} …/{$fin} est encore servie.");
    }

    public function test_sync_notes_rend_404_et_ne_marque_aucune_copie(): void
    {
        Http::preventStrayRequests();
        $ecole = Institution::factory()->create();
        $enseignant = User::factory()->for($ecole)->create(['role' => 'enseignant', 'klassci_enseignant_id' => 4242]);
        $evaluation = Evaluation::factory()->terminee()->create([
            'institution_id' => $ecole->id,
            'klassci_enseignant_id' => 4242,
            'klassci_evaluation_id' => 9001,
        ]);
        $copie = EvaluationSubmission::create([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => 5555,
            'attempt' => 1,
            'status' => 'soumis',
            'started_at' => now(),
            'submitted_at' => now(),
            'note_sur_20' => 12,
            'institution_id' => $ecole->id,
        ]);
        $this->disableKlassciMiddleware();

        $this->asTenant($enseignant)->postJson("/api/evaluations/{$evaluation->id}/sync-notes")->assertNotFound();

        self::assertFalse($copie->refresh()->synced_to_klassci);
    }

    public function test_participants_rend_404_au_personnel(): void
    {
        Http::preventStrayRequests();
        $ecole = Institution::factory()->standalone()->create();
        $coordinateur = User::factory()->for($ecole)->create(['role' => 'coordinateur', 'klassci_token' => null]);
        $seance = Seance::factory()->for($ecole)->create(['created_by' => $coordinateur->id]);
        $this->disableKlassciMiddleware();

        $this->asTenant($coordinateur)->getJson("/api/lms/seances/{$seance->id}/participants")->assertNotFound();
    }
}
