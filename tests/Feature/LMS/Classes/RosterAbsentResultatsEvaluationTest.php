<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Classes;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * « Absent n'est pas vide », vu depuis l'autre lecteur du roster partagé.
 *
 * `KlassciEnvelopeClasseRoster` sert aussi `GET /api/evaluations/{id}/results-by-class`
 * (`TeacherEvaluationResultsService:95`). Quand l'enveloppe ne portait pas de bloc
 * `etudiants`, il rendait `[]` : l'écran des notes affichait une classe sans
 * élève. Il lève désormais `KlassciUnavailableException` ; ce test prouve que la
 * chaîne existante (le service relance toute `RuntimeException`, le contrôleur la
 * rend par `RendersKlassciBackedErrors`) en fait un 503 — et pas un 500.
 *
 * Volet KLASSCI seul : la route lit un roster KLASSCI ; un compte sans jeton y
 * reçoit déjà le 401 motivé, que ce changement ne touche pas.
 *
 * @see app/Services/Classe/KlassciEnvelopeClasseRoster.php
 * @see app/Http/Controllers/API/Evaluation/EvaluationTeacherController.php
 */
final class RosterAbsentResultatsEvaluationTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const BASE_URL = 'https://ecole.klassci.test/api/lms';

    private const CLASSE_ID = 55;

    private User $enseignant;

    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $ecole = Institution::factory()->create([
            'klassci_api_url' => self::BASE_URL,
            'is_active' => true,
        ]);
        app(TenantManager::class)->set($ecole);

        $this->enseignant = User::factory()->teacher()->for($ecole)->create([
            'klassci_token' => 'jeton-enseignant',
            'klassci_tenant_url' => self::BASE_URL,
        ]);
        // Lire les notes est réservé au propriétaire de l'évaluation.
        $this->evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->id,
            'klassci_classe_id' => self::CLASSE_ID,
            'klassci_enseignant_id' => $this->enseignant->klassci_enseignant_id,
            'is_published' => true,
        ]);
    }

    /** @param  array<string, mixed>  $donnees */
    private function fakeEnveloppe(array $donnees): void
    {
        Http::fake([
            self::BASE_URL.'/classes/'.self::CLASSE_ID.'*' => Http::response(['success' => true, 'data' => $donnees]),
            '*' => Http::response(['success' => true, 'data' => []]),
        ]);
    }

    private function appeler(): TestResponse
    {
        return $this->asTenant($this->enseignant)
            ->getJson("/api/evaluations/{$this->evaluation->id}/results-by-class");
    }

    public function test_une_enveloppe_sans_bloc_etudiants_rend_503_et_non_une_classe_sans_eleve(): void
    {
        $this->fakeEnveloppe(['classe' => ['id' => self::CLASSE_ID, 'nom' => 'B2 COM']]);

        $this->appeler()
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertExactJson(['success' => false, 'message' => KlassciUnavailableException::CLIENT_MESSAGE]);
    }

    public function test_un_bloc_etudiants_present_et_vide_reste_une_classe_vide(): void
    {
        $this->fakeEnveloppe(['classe' => ['id' => self::CLASSE_ID, 'nom' => 'B2 COM'], 'etudiants' => []]);

        $reponse = $this->appeler()->assertOk();

        self::assertSame([], $reponse->json('data.resultats'));
        self::assertSame(0, $reponse->json('data.statistiques.total_etudiants'));
    }
}
