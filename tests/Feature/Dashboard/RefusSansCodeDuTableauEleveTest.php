<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Dashboard\StudentDashboardReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * `GET /proxy/me/dashboard` rend un refus métier avec le statut de la règle
 * unique ({@see BusinessException::statutHttp()}). Un refus levé sans code
 * d'erreur (le code par défaut, 0) sort en 422 avec son motif, et non en 500 :
 * un statut invalide faisait lever la réponse elle-même, hors du `try`.
 */
final class RefusSansCodeDuTableauEleveTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    public function test_un_refus_sans_code_sort_en_422_avec_son_motif(): void
    {
        $this->sourceRefuse(new BusinessException('Refus du tableau.', 0, reason: 'motif_de_test'));

        $this->asTenant($this->eleve())->getJson('/api/proxy/me/dashboard')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Refus du tableau.')
            ->assertJsonPath('reason', 'motif_de_test');
    }

    /** Témoin : un refus qui porte un statut d'erreur le garde. */
    public function test_un_refus_avec_son_statut_le_garde(): void
    {
        $this->sourceRefuse(new BusinessException('Inscription inactive.', 409, reason: 'enrolment_not_active'));

        $this->asTenant($this->eleve())->getJson('/api/proxy/me/dashboard')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'enrolment_not_active');
    }

    private function eleve(): User
    {
        $this->disableKlassciMiddleware();

        return $this->membre($this->ecole(), 'etudiant');
    }

    private function sourceRefuse(BusinessException $refus): void
    {
        $this->app->instance(StudentDashboardReader::class, new class($refus) implements StudentDashboardReader
        {
            public function __construct(private readonly BusinessException $refus) {}

            public function dashboardFor(User $student): array
            {
                throw $this->refus;
            }
        });
    }
}
