<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1666 — les six opérations du profil et des disponibilités d'un enseignant suivent `docs/openapi.yaml` : chaque
 * succès, et les refus décrits (422 par champ et par motif, 403 de la gestion et de l'école KLASSCI, 404).
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class ProfilEnseignantContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const PROFIL = '/lms/enseignants/{enseignantId}/profil';

    private const DISPOS = '/lms/enseignants/{enseignantId}/disponibilites';

    private const EXCEPTIONS = '/lms/enseignants/{enseignantId}/disponibilites/exceptions';

    private const EXCEPTION = '/lms/enseignants/{enseignantId}/disponibilites/exceptions/{exceptionId}';

    private Institution $ecole;

    private User $coordinateur;

    private User $awa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
        $this->awa = $this->membre($this->ecole, 'enseignant');
    }

    public function test_le_cycle_suit_la_spec(): void
    {
        $this->contrat($this->api()->getJson($this->url(self::PROFIL))->assertOk(), self::PROFIL, 'get');
        $this->contrat($this->api()->putJson($this->url(self::PROFIL), ['matricule' => 'P-1', 'grade' => 'professeur', 'statut' => 'en_activite', 'charge_hebdo_max' => 12])->assertOk(), self::PROFIL, 'put');
        $this->contrat($this->api()->putJson($this->url(self::DISPOS), ['semaine' => [['jour' => 1, 'debut' => '08:00', 'fin' => '12:00', 'disponible' => true]]])->assertOk(), self::DISPOS, 'put');
        $id = (int) $this->contrat($this->api()->postJson($this->url(self::EXCEPTIONS), ['date' => '2026-12-15', 'disponible' => false, 'motif' => 'Jury'])->assertCreated(), self::EXCEPTIONS, 'post')->json('data.id');
        $this->contrat($this->api()->getJson($this->url(self::DISPOS))->assertOk(), self::DISPOS, 'get');
        $this->contrat($this->api()->deleteJson(str_replace('{exceptionId}', (string) $id, $this->url(self::EXCEPTION)))->assertOk(), self::EXCEPTION, 'delete');
    }

    public function test_les_refus_suivent_la_spec(): void
    {
        $this->contrat($this->api()->putJson($this->url(self::PROFIL), ['grade' => 'docteur'])->assertUnprocessable(), self::PROFIL, 'put');
        $this->api()->putJson($this->url(self::PROFIL), ['matricule' => 'P-1'])->assertOk();
        $autre = $this->membre($this->ecole, 'enseignant');
        $this->contrat($this->api()->putJson("/api/lms/enseignants/{$autre->id}/profil", ['matricule' => 'P-1'])
            ->assertUnprocessable()->assertJsonPath('reason', 'matricule_deja_pris'), self::PROFIL, 'put');
        $this->contrat($this->api()->putJson($this->url(self::DISPOS), ['semaine' => [
            ['jour' => 1, 'debut' => '08:00', 'fin' => '12:00', 'disponible' => true],
            ['jour' => 1, 'debut' => '11:00', 'fin' => '13:00', 'disponible' => false],
        ]])->assertUnprocessable()->assertJsonPath('reason', 'disponibilites_en_conflit'), self::DISPOS, 'put');
        $this->contrat($this->api()->getJson('/api/lms/enseignants/424242/disponibilites')->assertNotFound(), self::DISPOS, 'get');
        $this->contrat($this->asTenant($this->awa)->putJson($this->url(self::PROFIL), [])->assertForbidden(), self::PROFIL, 'put');

        $klassci = $this->ecole(InstitutionMode::Klassci);
        $enseignant = $this->membre($klassci, 'enseignant');
        $this->contrat($this->asTenant($this->membre($klassci, 'coordinateur'))->getJson("/api/lms/enseignants/{$enseignant->id}/profil")
            ->assertForbidden()->assertJsonPath('reason', 'teacher_profile_managed_by_klassci'), self::PROFIL, 'get');
    }

    private function api(): static
    {
        return $this->asTenant($this->coordinateur);
    }

    private function url(string $chemin): string
    {
        return '/api'.str_replace('{enseignantId}', (string) $this->awa->id, $chemin);
    }

    private function contrat(TestResponse $reponse, string $chemin, string $methode): TestResponse
    {
        $this->assertMatchesOpenApiResponse($reponse, $chemin, $methode);

        return $reponse;
    }
}
