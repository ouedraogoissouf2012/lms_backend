<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\VisioActorAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Désactiver une visio suit la règle de gestion de la visio
 * (`VisioActorAuthorization::canManage`), comme l'activer, la démarrer et la
 * terminer : l'enseignant propriétaire ou le personnel de gestion de
 * l'établissement. Le refus a UNE forme, `managementRefusal()`, pour les trois
 * gestes de la séance.
 *
 * Vrai jeton porteur (`asTenant`) : le tenant est résolu comme en production.
 */
final class DesactiverVisioTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** Propriété KLASSCI lue sur `klassci_enseignant_id`, distinct du `klassci_id` du compte. */
    private const A = ['klassci_id' => 5777, 'klassci_enseignant_id' => 777];

    private const B = ['klassci_id' => 5888, 'klassci_enseignant_id' => 888];

    private Institution $ecole;

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => ['klassci'];
        yield 'ecole autonome' => ['standalone'];
    }

    #[DataProvider('volets')]
    public function test_l_enseignant_proprietaire_desactive_sa_visio(string $volet): void
    {
        $this->ecole($volet);
        $proprietaire = $this->compte('teacher', self::A);
        $seance = $this->seanceDe($proprietaire, $volet);

        $this->asTenant($proprietaire)
            ->postJson("/api/lms/seances/{$seance->id}/deactivate-visio")
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Visioconférence désactivée', 'data' => ['visio_enabled' => false]]);

        self::assertFalse((bool) self::etat($seance)['visio_enabled']);
    }

    #[DataProvider('volets')]
    public function test_un_coordinateur_de_l_etablissement_desactive_la_visio(string $volet): void
    {
        $this->ecole($volet);
        $seance = $this->seanceDe($this->compte('teacher', self::A), $volet);
        $coordinateur = $this->compte('coordinator');

        $this->asTenant($coordinateur)
            ->postJson("/api/lms/seances/{$seance->id}/deactivate-visio")
            ->assertOk()
            ->assertJsonPath('data.visio_enabled', false);

        self::assertFalse((bool) self::etat($seance)['visio_enabled']);
        self::assertSame($coordinateur->id, (int) self::etat($seance)['updated_by']);
    }

    #[DataProvider('volets')]
    public function test_un_autre_enseignant_de_l_etablissement_recoit_le_refus_motive_sans_effet(string $volet): void
    {
        $this->ecole($volet);
        $seance = $this->seanceDe($this->compte('teacher', self::A), $volet);
        $avant = self::etat($seance);

        $this->asTenant($this->compte('teacher', self::B))
            ->postJson("/api/lms/seances/{$seance->id}/deactivate-visio")
            ->assertForbidden()
            ->assertExactJson(VisioActorAuthorization::managementRefusal());

        self::assertSame($avant, self::etat($seance));
    }

    #[DataProvider('volets')]
    public function test_un_enseignant_d_un_autre_etablissement_ne_trouve_pas_la_seance(string $volet): void
    {
        $this->ecole($volet);
        $seance = $this->seanceDe($this->compte('teacher', self::A), $volet);
        $avant = self::etat($seance);
        $ailleurs = User::factory()->teacher()->for(Institution::factory()->create())->create(self::A);

        $this->asTenant($ailleurs)
            ->postJson("/api/lms/seances/{$seance->id}/deactivate-visio")
            ->assertNotFound()
            ->assertJsonPath('success', false);

        self::assertSame($avant, self::etat($seance));
    }

    /** Démarrer, terminer et désactiver refusent avec le MÊME corps, celui de `managementRefusal()`. */
    public function test_les_trois_gestes_refusent_avec_le_meme_corps(): void
    {
        $this->ecole('standalone');
        $proprietaire = $this->compte('teacher', self::A);
        $autre = $this->compte('teacher', self::B);
        $gestes = [
            'start' => Seance::factory()->for($this->ecole)->withVisio()->create(['created_by' => $proprietaire->id]),
            'end' => Seance::factory()->for($this->ecole)->visioActive()->create(['created_by' => $proprietaire->id]),
            'deactivate' => Seance::factory()->for($this->ecole)->withVisio()->create(['created_by' => $proprietaire->id]),
        ];

        foreach ($gestes as $geste => $seance) {
            $this->asTenant($autre)
                ->postJson("/api/lms/seances/{$seance->id}/{$geste}-visio")
                ->assertForbidden()
                ->assertExactJson(VisioActorAuthorization::managementRefusal());
        }
    }

    private function ecole(string $volet): void
    {
        $this->disableKlassciMiddleware();
        Http::fake();
        Queue::fake();
        $this->ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
    }

    /** @param  array<string, mixed>  $attributs */
    private function compte(string $role, array $attributs = []): User
    {
        return User::factory()->{$role}()->for($this->ecole)->create($attributs);
    }

    /** Une séance à la visio programmée, dont `$enseignant` est propriétaire ; locale dans une école autonome. */
    private function seanceDe(User $enseignant, string $volet): Seance
    {
        return Seance::factory()->for($this->ecole)->withVisio()->create($volet === 'standalone'
            ? [
                'created_by' => $enseignant->id,
                'klassci_seance_id' => null,
                'klassci_enseignant_id' => null,
                'klassci_classe_id' => null,
                'klassci_matiere_id' => null,
            ]
            : ['klassci_enseignant_id' => $enseignant->klassci_enseignant_id]);
    }

    /**
     * L'état visio de la ligne, lu brut en base.
     *
     * @return array<string, mixed>
     */
    private static function etat(Seance $seance): array
    {
        return (array) DB::table('seances')
            ->where('id', $seance->id)
            ->first(['visio_enabled', 'visio_type', 'visio_status', 'visio_room_id', 'visio_active', 'updated_by']);
    }
}
