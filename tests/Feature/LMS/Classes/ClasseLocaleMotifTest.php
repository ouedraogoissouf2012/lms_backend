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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Le 409 de `GET /lms/classes/local/{id}` porte un motif (ADR-906-01).
 *
 * Une classe créée localement n'a aucune fiche KLASSCI : la porte rend 409. Le
 * front ne lit que le statut et le motif (`normalizeError`) ; sans motif, ce
 * refus métier devenait un « conflit » générique. Le 404 d'une classe
 * introuvable, lui, n'a qu'un sens : il reste sans motif.
 *
 * Les deux volets sont joués : une classe locale dans une école KLASSCI, et dans
 * une école autonome. Vrai jeton porteur (`asTenant`), jamais `Sanctum::actingAs`.
 *
 * @see ClasseLocalDetailsService
 */
final class ClasseLocaleMotifTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'classe locale dans une ecole klassci' => ['klassci'];
        yield 'classe locale dans une ecole autonome' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_une_classe_locale_rend_un_409_motive(string $volet): void
    {
        $this->disableKlassciMiddleware();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
        $ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);
        $enseignant = User::factory()->teacher()->create([
            'institution_id' => $ecole->id,
            'klassci_id' => $volet === 'standalone' ? null : 920001,
        ]);

        $this->asTenant($enseignant)
            ->getJson("/api/lms/classes/local/{$classe->id}")
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => "Cette classe a été créée localement : elle n'a pas de fiche KLASSCI à afficher.",
                'reason' => 'classe_local_only',
            ]);
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
