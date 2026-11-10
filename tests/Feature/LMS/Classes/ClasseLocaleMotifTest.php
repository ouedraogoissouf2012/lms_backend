<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Classes;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Classe\ClasseLocalDetailsService;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Le 409 de `GET /lms/classes/local/{id}` porte un motif (ADR-906-01).
 *
 * Une classe créée dans le LMS n'a aucune fiche KLASSCI : la porte rend 409. Le
 * front ne lit que le statut et le motif (`normalizeError`) ; sans motif, ce
 * refus métier devenait un « conflit » générique. Le 404 d'une classe
 * introuvable, lui, n'a qu'un sens : il reste sans motif.
 *
 * Ce 409 ne vaut que dans une école créée depuis KLASSCI. Dans une école créée
 * depuis le LMS, toutes les classes sont créées dans le LMS : la porte y lit la
 * fiche locale (ADR-760-01, amendement EI-02), et une classe que l'appelant
 * n'atteint pas rend le 404 sans motif. Vrai jeton porteur (`asTenant`), jamais
 * `Sanctum::actingAs`.
 *
 * @see ClasseLocalDetailsService
 */
final class ClasseLocaleMotifTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** Classe créée dans le LMS, école créée depuis KLASSCI. */
    public function test_une_classe_locale_rend_un_409_motive(): void
    {
        $this->disableKlassciMiddleware();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
        $ecole = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);
        $enseignant = User::factory()->teacher()->create(['institution_id' => $ecole->id, 'klassci_id' => 920001]);

        $this->asTenant($enseignant)
            ->getJson("/api/lms/classes/local/{$classe->id}")
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => "Cette classe a été créée localement : elle n'a pas de fiche KLASSCI à afficher.",
                'reason' => 'classe_local_only',
            ]);
    }

    /**
     * Classe créée dans le LMS, école créée depuis le LMS : plus de 409. Un
     * enseignant qui n'y est pas désigné ne l'atteint pas : le 404 sans motif,
     * sans aucun appel à KLASSCI.
     */
    public function test_dans_une_ecole_creee_depuis_le_lms_la_porte_ne_rend_plus_de_409(): void
    {
        $this->disableKlassciMiddleware();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
        $ecole = Institution::factory()->standalone()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);
        $enseignant = User::factory()->teacher()->create([
            'institution_id' => $ecole->id,
            'klassci_id' => null,
            'klassci_token' => null,
        ]);

        $this->asTenant($enseignant)
            ->getJson("/api/lms/classes/local/{$classe->id}")
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Classe non trouvée']);
    }

    public function test_une_classe_introuvable_reste_un_404_sans_motif(): void
    {
        $this->disableKlassciMiddleware();
        $ecole = Institution::factory()->standalone()->create();
        $autre = Institution::factory()->standalone()->create();
        $etrangere = Classe::factory()->create(['institution_id' => $autre->id, 'klassci_id' => null]);
        $enseignant = User::factory()->teacher()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);

        $this->asTenant($enseignant)
            ->getJson("/api/lms/classes/local/{$etrangere->id}")
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Classe non trouvée']);
    }
}
