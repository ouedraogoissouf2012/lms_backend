<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Seul le gestionnaire de la séance démarre et termine sa visio : la règle de
 * gestion de la visio (`VisioActorAuthorization::canManage`), celle qui décide
 * déjà de l'entrée en salle et de la validation d'un participant.
 *
 * Gestionnaire : l'enseignant propriétaire (`klassci_enseignant_id` dans une école
 * KLASSCI, `created_by` pour une séance locale), ou le personnel de gestion de
 * l'établissement (coordinateur, superAdmin).
 *
 * Vrai jeton porteur (`asTenant`), jamais `Sanctum::actingAs` : le tenant est
 * résolu comme en production.
 */
final class DemarrerTerminerVisioTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /**
     * La propriété KLASSCI se lit sur `klassci_enseignant_id`. Le `klassci_id` du
     * compte est volontairement différent : ces tests ne reposent pas sur le repli
     * `klassci_id` de `teacherOwns`.
     */
    private const A = ['klassci_id' => 5777, 'klassci_enseignant_id' => 777];

    private const B = ['klassci_id' => 5888, 'klassci_enseignant_id' => 888];

    private const REFUS = [
        'success' => false,
        'message' => 'Vous ne pouvez pas gérer cette visio',
        'reason' => 'actor_not_authorized',
    ];

    private Institution $ecole;

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function voletsEtEtapes(): iterable
    {
        foreach (['ecole klassci' => 'klassci', 'ecole autonome' => 'standalone'] as $nomVolet => $volet) {
            yield "{$nomVolet}, demarrer" => [$volet, 'start'];
            yield "{$nomVolet}, terminer" => [$volet, 'end'];
        }
    }

    private function ecole(string $volet): Institution
    {
        $this->disableKlassciMiddleware();
        Http::fake();
        Queue::fake();

        return $this->ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
    }

    /** @param  array<string, mixed>  $attributs */
    private function compte(string $role, array $attributs = []): User
    {
        return User::factory()->{$role}()->for($this->ecole)->create($attributs);
    }

    /**
     * Une séance dont `$enseignant` est propriétaire, dans l'état que l'étape
     * attend : programmée pour démarrer, active pour terminer.
     *
     * Volet autonome : une séance locale, sans aucun identifiant KLASSCI.
     */
    private function seanceDe(User $enseignant, string $volet, string $etape): Seance
    {
        $fabrique = Seance::factory()->for($this->ecole);
        $fabrique = $etape === 'start' ? $fabrique->withVisio() : $fabrique->visioActive();

        return $fabrique->create($volet === 'standalone'
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
     * L'état visio de la ligne, lu brut en base : une écriture qui le touche se voit.
     *
     * @return array<string, mixed>
     */
    private static function etat(Seance $seance): array
    {
        return (array) DB::table('seances')
            ->where('id', $seance->id)
            ->first(['visio_status', 'visio_active', 'visio_started_at', 'visio_ended_at', 'updated_by']);
    }

    private static function statutApres(string $etape): string
    {
        return $etape === 'start' ? 'active' : 'terminee';
    }

    #[DataProvider('voletsEtEtapes')]
    public function test_l_enseignant_proprietaire_demarre_et_termine_sa_seance(string $volet, string $etape): void
    {
        $this->ecole($volet);
        $proprietaire = $this->compte('teacher', self::A);
        $seance = $this->seanceDe($proprietaire, $volet, $etape);

        $this->asTenant($proprietaire)
            ->postJson("/api/lms/seances/{$seance->id}/{$etape}-visio")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.visio_status', self::statutApres($etape));

        self::assertSame(self::statutApres($etape), $seance->fresh()?->visio_status);
    }

    #[DataProvider('voletsEtEtapes')]
    public function test_un_coordinateur_de_l_etablissement_demarre_et_termine_la_seance(string $volet, string $etape): void
    {
        $this->ecole($volet);
        $seance = $this->seanceDe($this->compte('teacher', self::A), $volet, $etape);

        $this->asTenant($this->compte('coordinator'))
            ->postJson("/api/lms/seances/{$seance->id}/{$etape}-visio")
            ->assertOk()
            ->assertJsonPath('data.visio_status', self::statutApres($etape));

        self::assertSame(self::statutApres($etape), $seance->fresh()?->visio_status);
    }

    #[DataProvider('voletsEtEtapes')]
    public function test_un_autre_enseignant_de_l_etablissement_recoit_le_refus_motive_sans_effet(
        string $volet,
        string $etape,
    ): void {
        $this->ecole($volet);
        $seance = $this->seanceDe($this->compte('teacher', self::A), $volet, $etape);
        $avant = self::etat($seance);

        $this->asTenant($this->compte('teacher', self::B))
            ->postJson("/api/lms/seances/{$seance->id}/{$etape}-visio")
            ->assertForbidden()
            ->assertExactJson(self::REFUS);

        self::assertSame($avant, self::etat($seance));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('voletsEtEtapes')]
    public function test_un_enseignant_d_un_autre_etablissement_ne_trouve_pas_la_seance(string $volet, string $etape): void
    {
        $this->ecole($volet);
        $seance = $this->seanceDe($this->compte('teacher', self::A), $volet, $etape);
        $ailleurs = User::factory()->teacher()->for(Institution::factory()->create())->create(self::A);

        $this->asTenant($ailleurs)
            ->postJson("/api/lms/seances/{$seance->id}/{$etape}-visio")
            ->assertNotFound()
            ->assertJsonPath('success', false);

        self::assertNotSame(self::statutApres($etape), $seance->fresh()?->visio_status);
        Queue::assertNothingPushed();
    }
}
