<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1669 — les neuf opérations des emplois du temps suivent `docs/openapi.yaml` : chaque succès, et les refus décrits
 * (404, 422 avec motif, 403 de la gestion et de l'école KLASSCI). Le rapport de génération y est validé avec une séance
 * ignorée pour conflit, pour que la forme de `ignorees` soit réellement confrontée.
 *
 * Jeton Bearer RÉEL, par `asTenant()` ; horloge figée un lundi.
 */
final class EmploisDuTempsContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const RACINE = '/lms/emplois-du-temps';

    private const UN = '/lms/emplois-du-temps/{emploiId}';

    private Institution $ecole;

    private User $coordinateur;

    private Classe $classe;

    private int $matiere;

    private User $enseignant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-07 06:00:00');
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
        $this->classe = $this->classe($this->ecole, 'Terminale A');
        $this->enseignant = $this->membre($this->ecole, 'enseignant');
        $maths = $this->matiere($this->ecole, 'Maths');
        $this->matiere = $maths->id;
        $this->programme($this->classe, $maths, $this->enseignant);
    }

    public function test_le_cycle_d_un_emploi_du_temps_suit_la_spec(): void
    {
        $cree = $this->contrat($this->api()->postJson('/api'.self::RACINE, $this->champs())->assertCreated(), self::RACINE, 'post');
        $id = (int) $cree->json('data.id');
        $un = str_replace('{emploiId}', (string) $id, self::UN);

        $this->contrat($this->api()->putJson("/api{$un}/lignes", ['lignes' => [
            ['jour' => 1, 'debut' => '08:00', 'fin' => '10:00', 'matiere_id' => $this->matiere, 'type_seance' => 'cm'],
            ['jour' => 1, 'debut' => '10:00', 'fin' => '10:15', 'libelle' => 'Récréation'],
        ]])->assertOk(), self::UN.'/lignes', 'put');
        Seance::factory()->for($this->ecole)->create([
            'date_seance' => '2026-12-14 08:30:00', 'fin_seance' => '2026-12-14 09:00:00', 'created_by' => $this->enseignant->id,
            'is_active' => true, 'klassci_seance_id' => null, 'klassci_classe_id' => null, 'klassci_enseignant_id' => null,
        ]);
        $rapport = $this->contrat($this->api()->postJson("/api{$un}/generer")->assertOk(), self::UN.'/generer', 'post');
        self::assertSame('conflit', $rapport->json('data.ignorees.0.motif'), 'La forme d\'une séance ignorée est confrontée.');

        $this->contrat($this->api()->getJson("/api{$un}")->assertOk(), self::UN, 'get');
        $this->contrat($this->api()->putJson("/api{$un}", ['titre' => 'S2', 'du' => '2026-12-07', 'au' => '2026-12-31'])->assertOk(), self::UN, 'put');
        $this->contrat($this->api()->getJson('/api'.self::RACINE)->assertOk(), self::RACINE, 'get');
        $this->contrat($this->api()->getJson('/api'.self::RACINE.'/alertes')->assertOk(), self::RACINE.'/alertes', 'get');
        $soeur = $this->classe($this->ecole, 'Terminale B');
        $this->contrat($this->api()->postJson("/api{$un}/copier", ['classe_id' => $soeur->id, 'du' => '2026-12-07', 'au' => '2026-12-13'])
            ->assertUnprocessable()->assertJsonPath('reason', 'matiere_hors_programme'), self::UN.'/copier', 'post');
        $this->contrat($this->api()->deleteJson("/api{$un}")->assertOk(), self::UN, 'delete');
    }

    public function test_les_refus_suivent_la_spec(): void
    {
        $this->contrat($this->api()->getJson('/api/lms/emplois-du-temps/424242')->assertNotFound(), self::UN, 'get');
        $this->contrat($this->api()->postJson('/api'.self::RACINE, ['titre' => 'S1'])->assertUnprocessable(), self::RACINE, 'post');
        $this->contrat($this->asTenant($this->enseignant)->getJson('/api'.self::RACINE)->assertForbidden(), self::RACINE, 'get');
        $klassci = $this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur');
        $this->contrat($this->asTenant($klassci)->getJson('/api'.self::RACINE.'/alertes')
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci'), self::RACINE.'/alertes', 'get');
    }

    private function api(): static
    {
        return $this->asTenant($this->coordinateur);
    }

    /** @return array<string, mixed> */
    private function champs(): array
    {
        return ['classe_id' => $this->classe->id, 'titre' => 'Semestre 1', 'du' => '2026-12-07', 'au' => '2026-12-20'];
    }

    private function contrat(TestResponse $reponse, string $chemin, string $methode): TestResponse
    {
        $this->assertMatchesOpenApiResponse($reponse, $chemin, $methode);

        return $reponse;
    }
}
