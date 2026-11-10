<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * D72, E1, l'autre volet : dans une école créée depuis KLASSCI, la création
 * d'une évaluation reste une mise en ligne d'une évaluation PROGRAMMÉE dans
 * KLASSCI.
 *
 * Ce qui ne change pas est figé ici : le refus d'un compte sans identité
 * enseignant KLASSCI, les libellés lus chez KLASSCI et la propriété par
 * `klassci_enseignant_id`. Deux choses changent, voulues et déclarées :
 * - l'auteur est aussi écrit dans `enseignant_id` (le même fait dans les deux
 *   types d'école) ;
 * - sans `klassci_evaluation_id`, la création est refusée (422,
 *   `programmation_klassci_requise`) : la saisie libre n'appartient qu'à l'école
 *   créée depuis le LMS. Les deux appelants du front envoient toujours la
 *   programmation (`useCreateQuestions.js:198`,
 *   `useTeacherEvaluationActions.js:65`).
 *
 * KLASSCI est simulé (`KlassciProxyService`) ; la route, le jeton et le tenant
 * sont réels.
 */
final class EvaluationsEcoleCreeeDepuisKlassciTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const CREER = '/api/evaluations';

    protected function setUp(): void
    {
        parent::setUp();
        // La resynchronisation du profil (#90) n'est pas l'objet de ce test :
        // elle partirait vers KLASSCI avec le jeton de l'enseignant.
        $this->disableKlassciMiddleware();
    }

    public function test_un_compte_sans_identite_enseignant_klassci_recoit_403_et_son_motif(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);

        $this->asTenant($this->membre($ecole, 'enseignant'))->postJson(self::CREER, $this->programmation())
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Vous devez être un enseignant KLASSCI synchronisé pour créer une évaluation.',
                'reason' => 'enseignant_klassci_requis',
            ]);

        self::assertSame(0, Evaluation::query()->count());
    }

    public function test_l_enseignant_met_en_ligne_une_evaluation_programmee_dans_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $moi = $this->enseignantKlassci($ecole, 42);
        $this->libellesKlassci();

        $this->asTenant($moi)->postJson(self::CREER, $this->programmation())
            ->assertCreated()
            ->assertJsonPath('data.klassci_classe_id', 20)
            ->assertJsonPath('data.klassci_matiere_id', 10)
            ->assertJsonPath('data.enseignant_id', $moi->getKey())
            ->assertJsonPath('data.classe_id', null)
            ->assertJsonPath('data.matiere_id', null);

        $evaluation = Evaluation::query()->sole();
        self::assertSame(900, $evaluation->klassci_evaluation_id);
        self::assertSame(42, $evaluation->klassci_enseignant_id);
        self::assertSame($moi->getKey(), $evaluation->enseignant_id);
        self::assertNull($evaluation->classe_id);
        self::assertNull($evaluation->matiere_id);
        self::assertSame('Mathématiques', $evaluation->matiere_nom);
        self::assertSame('Terminale D', $evaluation->classe_nom);
    }

    public function test_sans_programmation_klassci_la_creation_est_refusee(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $programmation = $this->programmation();
        unset($programmation['klassci_evaluation_id']);

        $this->asTenant($this->enseignantKlassci($ecole, 42))->postJson(self::CREER, $programmation)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('reason', 'programmation_klassci_requise');

        self::assertSame(0, Evaluation::query()->count());
    }

    public function test_un_identifiant_local_est_refuse_dans_une_ecole_creee_depuis_klassci(): void
    {
        // D132 : `klassci_*` d'un côté, `classe_id`/`matiere_id` de l'autre.
        // Un id local n'a pas d'espace où vivre dans ce type d'école.
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $moi = $this->enseignantKlassci($ecole, 42);

        foreach (['classe_id', 'matiere_id'] as $champ) {
            $this->asTenant($moi)->postJson(self::CREER, $this->programmation([$champ => 7]))
                ->assertUnprocessable()
                ->assertJsonPath('reason', 'identifiant_hors_espace');
        }

        self::assertSame(0, Evaluation::query()->count());
    }

    public function test_la_propriete_par_klassci_enseignant_id_reste_celle_des_evaluations_existantes(): void
    {
        // Une ligne d'avant E1 n'a pas d'`enseignant_id` : son auteur reste
        // reconnu par son identité KLASSCI, et un autre enseignant non.
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $auteur = $this->enseignantKlassci($ecole, 42);
        $autre = $this->enseignantKlassci($ecole, 43);
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_enseignant_id' => 42,
            'enseignant_id' => null,
            'is_locked' => false,
            'status' => 'brouillon',
            'date_evaluation' => now()->addDay(),
        ]);

        $this->asTenant($autre)->putJson('/api/evaluations/'.$evaluation->getKey(), ['titre' => 'Volée'])
            ->assertForbidden();
        $this->asTenant($auteur)->putJson('/api/evaluations/'.$evaluation->getKey(), ['titre' => 'Corrigée'])
            ->assertOk();

        self::assertSame('Corrigée', $evaluation->refresh()->titre);
    }

    // ───────────────────────── harnais

    private function enseignantKlassci(Institution $ecole, int $klassciEnseignantId): User
    {
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill([
            'klassci_enseignant_id' => $klassciEnseignantId,
            'klassci_token' => 'jeton-enseignant',
        ])->save();

        return $enseignant;
    }

    private function libellesKlassci(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getMatieres')->andReturn(['data' => [['id' => 10, 'nom' => 'Mathématiques']]]);
            $mock->shouldReceive('getClasses')->andReturn(['data' => [['id' => 20, 'libelle' => 'Terminale D']]]);
        });
    }

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function programmation(array $surcharges = []): array
    {
        return array_merge([
            'klassci_evaluation_id' => 900,
            'klassci_classe_id' => 20,
            'klassci_matiere_id' => 10,
            'titre' => 'Contrôle 1',
            'type' => 'qcm',
            'date_evaluation' => now()->addDay()->toIso8601String(),
            'duree_minutes' => 30,
        ], $surcharges);
    }
}
