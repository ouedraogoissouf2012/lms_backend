<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * La réponse de `POST /evaluations` (création) suit la spec (lot A des `allOf`).
 *
 * Avant : `data` était décrit par un `allOf` à une seule branche, qui ne servait qu'à
 * rendre une référence nullable. Le validateur du dépôt refuse `allOf` et échoue fermé :
 * aucun test de contrat ne pouvait traverser cette réponse. Désormais : `nullable` et
 * `oneOf` à une branche, que le validateur lit.
 *
 * Vraie route, vrai jeton (`asTenant`), école créée depuis le LMS.
 */
final class CreationEvaluationSuitLaSpecTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    public function test_la_creation_par_l_enseignant_designe_suit_la_spec(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);

        $reponse = $this->asTenant($moi)->postJson('/api/evaluations', [
            'classe_id' => $classe->getKey(),
            'matiere_id' => $maths->getKey(),
            'titre' => 'Contrôle 1',
            'type' => 'qcm',
            'date_evaluation' => now()->addDay()->toIso8601String(),
            'duree_minutes' => 30,
        ]);

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations', 'post');
        $reponse->assertSuccessful()->assertJsonPath('data.classe_id', $classe->getKey());
    }

    /**
     * Le 409 « une version en ligne existe déjà » porte la version existante dans
     * `data` : c'est le nœud décrit par l'`allOf` d'avant.
     */
    public function test_le_409_de_la_version_en_ligne_existante_suit_la_spec(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['klassci_enseignant_id' => 77, 'klassci_token' => 'jeton'])->save();
        $this->disableKlassciMiddleware();
        $existante = Evaluation::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_evaluation_id' => 4242]);

        $reponse = $this->asTenant($enseignant)->postJson('/api/evaluations', [
            'klassci_evaluation_id' => 4242,
            'titre' => 'Seconde version',
            'type' => 'qcm',
            'date_evaluation' => now()->addDay()->toIso8601String(),
            'duree_minutes' => 30,
        ]);

        $this->assertMatchesOpenApiResponse($reponse, '/evaluations', 'post');
        $reponse->assertStatus(409)->assertJsonPath('data.id', $existante->getKey());
    }
}
