<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests de sécurité — Injection SQL (#212).
 *
 * Le projet repose sur Eloquent (bindings paramétrés) + recherche `LIKE`
 * bindée + tris en `switch/match` (jamais de concaténation d'input). Ces
 * tests AFFIRMENT cette protection : ils envoient des payloads d'injection
 * classiques sur chaque surface (recherche, tri, filtres, route param) et
 * vérifient qu'aucun SQL dangereux n'est exécuté — la table cible reste
 * intacte et la requête est traitée sans erreur 500 fuitant du SQL.
 *
 * @see app/Services/SeancesHistoryQueryService.php (search LIKE bindé)
 * @see app/Services/Quiz/QuizCrudService.php (sort switch)
 * @see app/Services/AdminAnalytics/ActivityTrendsService.php (selectRaw whitelisté)
 */
final class SqlInjectionTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $student;

    /**
     * Payloads d'injection SQL classiques — chacun tenterait de casser une
     * requête vulnérable (drop table, tautologie, union, commentaire, stacked).
     *
     * @return list<string>
     */
    private function injectionPayloads(): array
    {
        return [
            "'; DROP TABLE users; --",
            "' OR '1'='1",
            "1; DELETE FROM lessons WHERE 1=1; --",
            "' UNION SELECT password FROM users --",
            "admin'--",
            "\" OR \"\"=\"",
            "1' AND SLEEP(5)--",
            "'; UPDATE users SET role='admin' WHERE '1'='1",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
    }

    public function test_search_param_on_seances_history_is_injection_safe(): void
    {
        Seance::factory()->create([
            'institution_id' => $this->institution->id,
            'visio_enabled' => true,
            'visio_started_at' => now()->subHour(),
        ]);
        $usersBefore = User::count();
        $seancesBefore = Seance::count();

        Sanctum::actingAs($this->teacher);

        foreach ($this->injectionPayloads() as $payload) {
            $response = $this->getJson('/api/lms/seances/history?search=' . urlencode($payload));

            // La requête est traitée proprement (jamais de 500 fuitant du SQL).
            $this->assertNotSame(
                500,
                $response->status(),
                "Le payload [{$payload}] a provoqué une erreur serveur — risque d'injection"
            );

            // Les tables n'ont PAS été altérées par le payload.
            $this->assertSame($usersBefore, User::count(), "Table users altérée par [{$payload}]");
            $this->assertSame($seancesBefore, Seance::count(), "Table seances altérée par [{$payload}]");
        }
    }

    public function test_sort_param_on_quizzes_is_injection_safe(): void
    {
        Quiz::factory()->create([
            'institution_id' => $this->institution->id,
            'created_by' => $this->teacher->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->student);

        // Baseline mesuré après auth (un 1er appel authentifié peut déclencher
        // un sync) — on vérifie ensuite que les payloads ne FONT PAS chuter ce
        // compte (DROP/DELETE), seul signal réel d'une injection réussie.
        $this->getJson('/api/quizzes')->assertStatus(200);
        $usersBaseline = User::count();
        $teacherStillExists = User::whereKey($this->teacher->id)->exists();

        foreach ($this->injectionPayloads() as $payload) {
            // Le tri passe par un switch : un payload tombe dans le `default`,
            // jamais dans une clause ORDER BY concaténée.
            $response = $this->getJson('/api/quizzes?sort=' . urlencode($payload));

            $response->assertStatus(200);
            $this->assertGreaterThanOrEqual(
                $usersBaseline,
                User::count(),
                "Table users amputée par sort=[{$payload}] — injection probable"
            );
            $this->assertTrue(
                User::whereKey($this->teacher->id)->exists() && $teacherStillExists,
                "L'enseignant a disparu après sort=[{$payload}] — injection probable"
            );
        }
    }

    public function test_id_filters_are_cast_and_injection_safe(): void
    {
        Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'enseignant_id' => $this->teacher->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        $lessonsBefore = Lesson::count();

        Sanctum::actingAs($this->student);

        foreach (["1 OR 1=1", "1; DROP TABLE lessons", "' OR '1'='1"] as $payload) {
            $response = $this->getJson('/api/lessons?matiere_id=' . urlencode($payload));

            $this->assertNotSame(500, $response->status());
            $this->assertSame($lessonsBefore, Lesson::count(), "Table lessons altérée par matiere_id=[{$payload}]");
        }
    }

    public function test_injection_in_route_id_is_safe(): void
    {
        Sanctum::actingAs($this->student);

        // Un ID de route non numérique ne doit jamais atteindre une requête
        // vulnérable — 404/403/422, jamais 500 ni exécution SQL.
        foreach (["1 OR 1=1", "1;DROP TABLE quizzes"] as $payload) {
            $response = $this->getJson('/api/quizzes/' . urlencode($payload));

            $this->assertContains(
                $response->status(),
                [403, 404, 422],
                "ID malveillant [{$payload}] a renvoyé {$response->status()} au lieu de 403/404/422"
            );
        }
    }

    public function test_login_credentials_are_injection_safe(): void
    {
        $usersBefore = User::count();

        foreach ($this->injectionPayloads() as $payload) {
            $response = $this->postJson('/api/auth/login', [
                'email' => $payload,
                'password' => $payload,
            ]);

            // Jamais d'authentification réussie via injection, jamais de 500.
            $this->assertNotSame(200, $response->status(), "Login réussi via injection [{$payload}]");
            $this->assertNotSame(500, $response->status());
            $this->assertSame($usersBefore, User::count());
        }
    }
}
