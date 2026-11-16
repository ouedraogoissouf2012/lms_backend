<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * `GET /my-grades` rend le temps passé sur une copie (#901).
 *
 * `temps_passe` lisait un attribut inexistant et valait toujours `null`. C'est
 * désormais la durée de la copie, la remise moins le début, en minutes, la minute
 * entamée comptée ; `null` quand l'une des deux dates manque. Le front l'affiche
 * (`GradesTable.vue`, `formatTemps(minutes)`). Les deux volets lisent le même
 * agrégateur.
 */
final class TempsPasseDeLaCopieTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('volets')]
    public function test_le_temps_passe_est_la_duree_de_la_copie_minute_entamee_comptee(InstitutionMode $mode): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluation($mode);
        $remise = now()->subHour();
        $this->copie($evaluation, $eleve, debut: $remise->copy()->subMinutes(25)->subSeconds(10), remise: $remise);

        $this->asTenant($eleve)->getJson('/api/my-grades')
            ->assertOk()
            ->assertJsonPath('data.matieres.0.evaluations.0.temps_passe', 26);
    }

    #[DataProvider('volets')]
    public function test_sans_debut_le_temps_passe_est_inconnu(InstitutionMode $mode): void
    {
        [$eleve, $evaluation] = $this->eleveEtEvaluation($mode);
        $this->copie($evaluation, $eleve, debut: null, remise: now()->subHour());

        $this->asTenant($eleve)->getJson('/api/my-grades')
            ->assertOk()
            ->assertJsonPath('data.matieres.0.evaluations.0.temps_passe', null);
    }

    // ───────────────────────── fixtures

    /** @return array{0: User, 1: Evaluation} */
    private function eleveEtEvaluation(InstitutionMode $mode): array
    {
        $this->disableKlassciMiddleware();

        if ($mode === InstitutionMode::Standalone) {
            $ecole = $this->ecole();
            $eleve = $this->membre($ecole, 'etudiant');
            $classe = $this->classe($ecole, '6e A');
            $this->inscrire($eleve, $classe, 'actif');
            $maths = $this->matiere($ecole, 'Maths');

            return [$eleve, $this->evaluation($ecole, [
                'classe_id' => $classe->getKey(),
                'matiere_id' => $maths->getKey(),
                'matiere_nom' => 'Maths',
                'klassci_matiere_id' => null,
                'klassci_classe_id' => null,
            ])];
        }

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getMatieres')->andReturn(['success' => true, 'data' => []]);
        });
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $eleve = User::factory()->student()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_id' => 7777,
            'klassci_token' => 'jeton-eleve',
        ]);

        return [$eleve, $this->evaluation($ecole, ['klassci_matiere_id' => 42, 'matiere_nom' => 'Maths'])];
    }

    /** @param  array<string, mixed>  $attributs */
    private function evaluation(Institution $ecole, array $attributs): Evaluation
    {
        return Evaluation::factory()->create(array_merge([
            'institution_id' => $ecole->getKey(),
            'is_published' => true,
            'show_results' => true,
            'status' => 'terminee',
            'coefficient' => 1,
            'date_evaluation' => now()->subDay(),
        ], $attributs));
    }

    private function copie(Evaluation $evaluation, User $eleve, mixed $debut, mixed $remise): void
    {
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->getKey(),
            'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->getKey(),
            'klassci_etudiant_id' => $eleve->klassci_id,
            'attempt' => 1,
            'status' => 'soumis',
            'feedback' => null,
            'note_sur_20' => '12.00',
            'started_at' => $debut,
            'submitted_at' => $remise,
        ]);
    }
}
