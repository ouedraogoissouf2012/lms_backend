<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Exceptions\BusinessException;
use App\Models\Institution;
use App\Models\User;
use App\Services\Institution\InstitutionCrudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Un refus métier de la gestion des établissements suit la règle unique : son statut
 * ({@see BusinessException::statutHttp()}) et son motif (`reason`). Avant, le contrôleur le
 * rendait toujours en 422, sans motif : un refus 409 y aurait perdu son statut et son motif.
 *
 * Aucun refus du service ne porte de code aujourd'hui (il sort en 422, inchangé). Le refus
 * 409 est donc provoqué par la seule couture du service : son journal, remplacé par une
 * liaison contextuelle qui ne touche que `InstitutionCrudService` (le service est `final`).
 */
final class RefusMetierDesEtablissementsTest extends TestCase
{
    use RefreshDatabase;

    private User $supradmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);
    }

    /** Un refus 409 garde son statut et son motif. */
    public function test_un_refus_409_garde_son_statut_et_son_motif(): void
    {
        $journal = Mockery::mock(LoggerInterface::class);
        $journal->shouldReceive('info')->andThrow(new BusinessException('Refus de test.', 409, reason: 'motif_de_test'));
        $journal->shouldIgnoreMissing();
        $this->app->when(InstitutionCrudService::class)->needs(LoggerInterface::class)->give(static fn (): LoggerInterface => $journal);
        Institution::factory()->create(['is_active' => true]);
        $ecole = Institution::factory()->create(['is_active' => false]);

        $this->patchJson("/api/admin/institutions/{$ecole->id}/toggle", [], $this->bearer())
            ->assertStatus(409)
            ->assertExactJson(['success' => false, 'message' => 'Refus de test.', 'reason' => 'motif_de_test']);
    }

    /** Témoin : le vrai refus (désactiver la dernière école active) reste un 422, sans motif. */
    public function test_le_refus_de_la_derniere_ecole_active_reste_un_422(): void
    {
        $seule = Institution::factory()->create(['is_active' => true]);

        $this->patchJson("/api/admin/institutions/{$seule->id}/toggle", [], $this->bearer())
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => 'Impossible de désactiver la dernière institution active']);
    }

    /** Témoin : supprimer une école encore active reste un 422, sans motif. */
    public function test_le_refus_de_supprimer_une_ecole_active_reste_un_422(): void
    {
        $active = Institution::factory()->create(['is_active' => true]);

        $this->deleteJson("/api/admin/institutions/{$active->id}", [], $this->bearer())
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => 'Désactivez l\'institution avant de la supprimer.']);
    }

    /** @return array<string, string> */
    private function bearer(): array
    {
        return ['Authorization' => 'Bearer '.$this->supradmin->createToken('refus')->plainTextToken];
    }
}
