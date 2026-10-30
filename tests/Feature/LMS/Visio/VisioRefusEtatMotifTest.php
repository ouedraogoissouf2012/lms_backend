<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\VisioStatusRefusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Le refus dû à l'ÉTAT de la visio porte le motif de cet état (ADR-906-01).
 *
 * Avant : `POST /lms/seances/{id}/join` rendait `400 « La visio n'est pas
 * active »` sans motif, pour une visio programmée, terminée ou absente ; et la
 * validation d'un participant disait `visio_not_started` (« n'a pas encore
 * démarré ») pour une visio TERMINÉE.
 *
 * Après : une seule table (`VisioStatusRefusal`) donne `visio_not_started` pour
 * une visio programmée, `visio_ended` pour une visio terminée, et
 * `visio_not_enabled` pour une séance sans visio. Les statuts HTTP ne changent pas.
 *
 * Les deux volets sont joués : une séance d'une école KLASSCI et une séance
 * d'une école autonome. Vrai jeton porteur (`asTenant`).
 *
 * @see VisioStatusRefusal
 */
final class VisioRefusEtatMotifTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function etatsDeJoin(): iterable
    {
        foreach (['klassci', 'standalone'] as $volet) {
            yield "{$volet} : visio programmee" => [$volet, 'withVisio', 'visio_not_started'];
            yield "{$volet} : visio terminee" => [$volet, 'visioEnded', 'visio_ended'];
            yield "{$volet} : seance sans visio" => [$volet, 'sansVisio', 'visio_not_enabled'];
        }
    }

    #[DataProvider('etatsDeJoin')]
    public function test_rejoindre_une_visio_non_active_dit_pourquoi(string $volet, string $etat, string $motif): void
    {
        $this->disableKlassciMiddleware();
        $ecole = $this->ecole($volet);
        $seance = $this->seance($ecole, $etat);
        $eleve = User::factory()->student()->create([
            'institution_id' => $ecole->id,
            'klassci_id' => $volet === 'standalone' ? null : 930001,
        ]);

        $this->asTenant($eleve)
            ->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => "La visio n'est pas active", 'reason' => $motif]);
    }

    #[DataProvider('volets')]
    public function test_valider_un_participant_d_une_visio_terminee_ne_dit_plus_pas_encore_demarree(string $volet): void
    {
        $this->disableKlassciMiddleware();
        $ecole = $this->ecole($volet);
        $seance = $this->seance($ecole, 'visioEnded');
        $coordinateur = User::factory()->coordinator()->create(['institution_id' => $ecole->id]);
        $eleve = User::factory()->student()->create(['institution_id' => $ecole->id]);

        $this->asTenant($coordinateur)
            ->postJson("/api/lms/seances/{$seance->id}/validate-participant", ['user_id' => $eleve->id])
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'authorized' => false,
                'reason' => 'visio_ended',
                'message' => 'La visioconférence est terminée',
            ]);
    }

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => ['klassci'];
        yield 'ecole autonome' => ['standalone'];
    }

    private function ecole(string $volet): Institution
    {
        return $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
    }

    private function seance(Institution $ecole, string $etat): Seance
    {
        $fabrique = Seance::factory();
        if ($etat !== 'sansVisio') {
            $fabrique = $fabrique->{$etat}();
        }

        return $fabrique->create(['institution_id' => $ecole->id, 'is_active' => true]);
    }
}
