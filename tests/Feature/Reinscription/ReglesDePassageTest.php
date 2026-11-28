<?php

declare(strict_types=1);

namespace Tests\Feature\Reinscription;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1663, PR 1 : les règles de passage d'une école autonome et la proposition par élève. Sous vrai jeton.
 *
 * - Règles de l'école (`/regles-de-passage`) ; la moyenne de passage EST `settings.seuil_reussite`, la seule source,
 *   que lit aussi le bilan de classe. Surcharge par classe (`/classes/{classe}/regles-de-passage`), même règle.
 * - Propositions (`/classes/{classe}/passages`) sur `BilanDeClasse` : passe, rattrapage, redouble, exclu ; `null`
 *   quand rien n'est à moyenner. Le maximum de redoublements se compte sur les décisions `redouble` déjà prises.
 * - Correction motivée (`PUT /classes/{classe}/passages/{eleve}`), auditée.
 * - KLASSCI : 403 `catalogue_managed_by_klassci`. Autre école : 404. Hors gestion : 403.
 */
final class ReglesDePassageTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private Institution $ecole;

    private Classe $classe;

    private User $admin;

    /** @var array<string, User> */
    private array $eleves = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_les_regles_de_l_ecole_et_le_seuil_unique(): void
    {
        $this->classeNotee();

        $this->asTenant($this->admin)->getJson('/api/regles-de-passage')->assertOk()->assertJsonPath('data', [
            'moyenne_passage' => 10, 'moyenne_rattrapage' => 8, 'max_matieres_rattrapage' => 2, 'redoublement_autorise' => true, 'max_redoublements' => 1,
        ]);
        $reponse = $this->asTenant($this->admin)->putJson('/api/regles-de-passage', ['moyenne_passage' => 11.5, 'moyenne_rattrapage' => 9, 'max_matieres_rattrapage' => 3, 'redoublement_autorise' => false, 'max_redoublements' => 0]);

        $reponse->assertOk()->assertJsonPath('data.moyenne_passage', 11.5);
        $this->assertMatchesOpenApiResponse($reponse, '/regles-de-passage', 'put');
        self::assertSame(11.5, (float) ($this->ecole->refresh()->settings['seuil_reussite'] ?? 0), 'Une seule source : le seuil de l\'école.');
        $this->asTenant($this->admin)->getJson("/api/lms/classes/{$this->classe->id}/resultats")->assertJsonPath('data.seuil_reussite', 11.5);
        $this->asTenant($this->admin)->putJson('/api/regles-de-passage', ['moyenne_passage' => 9, 'moyenne_rattrapage' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('moyenne_rattrapage');
    }

    /**
     * Le rattrapage non réglé SUIT le passage (passage − 2, au moins 0), à chaque niveau : une constante dépasserait le
     * passage d'une école au seuil bas. Une seule source, le seuil.
     */
    public function test_le_rattrapage_par_defaut_suit_le_passage(): void
    {
        $this->classeNotee();

        $this->asTenant($this->admin)->putJson('/api/regles-de-passage', ['moyenne_passage' => 7])
            ->assertOk()->assertJsonPath('data.moyenne_rattrapage', 5);
        $this->asTenant($this->admin)->putJson('/api/regles-de-passage', ['moyenne_passage' => 1])
            ->assertOk()->assertJsonPath('data.moyenne_rattrapage', 0);
    }

    /** Une seule règle d'école : la base la garantit (clé non nulle dans l'index unique), pas le service. */
    public function test_une_seule_regle_d_ecole_meme_ecrite_deux_fois(): void
    {
        $this->classeNotee();
        $this->asTenant($this->admin)->putJson('/api/regles-de-passage', ['max_redoublements' => 2])->assertOk();
        $this->asTenant($this->admin)->putJson('/api/regles-de-passage', ['max_redoublements' => 3])->assertOk();
        self::assertSame(1, DB::table('regles_de_passage')->where('institution_id', $this->ecole->id)->whereNull('classe_id')->count());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('regles_de_passage')->insert(['institution_id' => $this->ecole->id, 'classe_id' => null, 'classe_cle' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_la_surcharge_d_une_classe_et_son_retrait(): void
    {
        $this->classeNotee();

        $this->asTenant($this->admin)->putJson("/api/classes/{$this->classe->id}/regles-de-passage", ['moyenne_passage' => 13.5])
            ->assertOk()->assertJsonPath('data.effective.moyenne_passage', 13.5)->assertJsonPath('data.effective.moyenne_rattrapage', 11.5)
            ->assertJsonPath('data.surcharge.moyenne_passage', 13.5);
        $this->asTenant($this->admin)->getJson("/api/lms/classes/{$this->classe->id}/resultats")->assertJsonPath('data.seuil_reussite', 13.5);
        $this->asTenant($this->admin)->getJson("/api/classes/{$this->classe->id}/passages")->assertJsonPath('data.regles.moyenne_passage', 13.5);

        $this->asTenant($this->admin)->deleteJson("/api/classes/{$this->classe->id}/regles-de-passage")
            ->assertOk()->assertJsonPath('data.surcharge', null)->assertJsonPath('data.effective.moyenne_passage', 10);
    }

    public function test_une_proposition_par_eleve_dans_les_cinq_cas(): void
    {
        $this->classeNotee();
        $ailleurs = $this->classe($this->ecole, '6e B');
        DB::table('decisions_de_passage')->insert([
            'institution_id' => $this->ecole->id, 'classe_id' => $ailleurs->id, 'user_id' => $this->eleves['Dédé']->id,
            'proposition' => 'redouble', 'decision' => 'redouble', 'motif' => 'Année précédente', 'decide_par' => $this->admin->id,
            'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);

        $reponse = $this->asTenant($this->admin)->getJson("/api/classes/{$this->classe->id}/passages");

        $reponse->assertOk();
        $this->assertMatchesOpenApiResponse($reponse, '/classes/{classe}/passages', 'get');
        $parNom = collect((array) $reponse->json('data.eleves'))->keyBy('nom');
        self::assertSame(
            ['Awa' => 'passe', 'Binta' => 'redouble', 'Coffi' => 'rattrapage', 'Dédé' => 'exclu', 'Éli' => null],
            $parNom->map(static fn (array $l): ?string => $l['proposition'])->sortKeys()->all(),
        );
        self::assertSame(2, $parNom['Coffi']['matieres_sous_le_seuil']);
        self::assertSame(1, $parNom['Dédé']['redoublements_precedents']);
        $reponse->assertJsonPath('data.compteurs', ['passe' => 1, 'rattrapage' => 1, 'redouble' => 1, 'exclu' => 1, 'indetermine' => 1]);
    }

    public function test_corriger_une_proposition_avec_un_motif(): void
    {
        $this->classeNotee();
        $binta = $this->eleves['Binta'];

        $this->corriger($binta, ['decision' => 'rattrapage'])->assertUnprocessable()->assertJsonValidationErrors('motif');
        $this->corriger($binta, ['decision' => 'promu', 'motif' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $reponse = $this->corriger($binta, ['decision' => 'rattrapage', 'motif' => 'Malade au second trimestre']);

        $reponse->assertOk()->assertJsonPath('data.proposition', 'redouble')->assertJsonPath('data.decision', 'rattrapage')
            ->assertJsonPath('data.motif', 'Malade au second trimestre');
        $this->assertMatchesOpenApiResponse($reponse, '/classes/{classe}/passages/{eleve}', 'put');
        self::assertTrue(DB::table('audit_logs')->where('auditable_type', 'like', '%DecisionDePassage%')->exists());
        $this->asTenant($this->admin)->getJson("/api/classes/{$this->classe->id}/passages")->assertJsonPath('data.eleves.1.decision', 'rattrapage');
        $intrus = $this->membre($this->ecole, 'etudiant');
        $this->corriger($intrus, ['decision' => 'passe', 'motif' => 'x'])->assertNotFound();
    }

    public function test_refus_hors_gestion_hors_ecole_et_en_ecole_klassci(): void
    {
        $this->classeNotee();
        $this->asTenant($this->membre($this->ecole, 'enseignant'))->getJson("/api/classes/{$this->classe->id}/passages")->assertForbidden();

        $autre = $this->membre($this->ecole(), 'admin');
        $absente = $this->asTenant($autre)->getJson('/api/classes/999999/passages')->assertNotFound()->json();
        self::assertSame($absente, $this->asTenant($autre)->getJson("/api/classes/{$this->classe->id}/passages")->assertNotFound()->json());

        $klassci = $this->membre($this->ecole(InstitutionMode::Klassci), 'admin');
        $this->asTenant($klassci)->getJson('/api/regles-de-passage')->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
    }

    /**
     * Une classe MIROIR de l'école (venue de KLASSCI) n'a rien de secret : c'est un refus motivé (403
     * `catalogue_managed_by_klassci`), comme tous les refus KLASSCI du dépôt, et non « introuvable ». Le 404 reste
     * à une classe d'une autre école ou inconnue. Sur les cinq routes qui lisent une classe locale ; rien n'est écrit.
     */
    public function test_une_classe_miroir_de_l_ecole_est_un_refus_motive(): void
    {
        $this->classeNotee();
        $miroir = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 4242, 'effectif' => 0]);
        $eleve = array_values($this->eleves)[0];
        $introuvable = $this->asTenant($this->admin)->getJson('/api/classes/999999/passages')->assertNotFound()->json();

        $appels = [
            ['getJson', "/api/classes/{$miroir->id}/regles-de-passage", []],
            ['putJson', "/api/classes/{$miroir->id}/regles-de-passage", ['moyenne_passage' => 12]],
            ['deleteJson', "/api/classes/{$miroir->id}/regles-de-passage", []],
            ['getJson', "/api/classes/{$miroir->id}/passages", []],
            ['putJson', "/api/classes/{$miroir->id}/passages/{$eleve->id}", ['decision' => 'passe', 'motif' => 'Bon trimestre']],
        ];
        foreach ($appels as [$verbe, $chemin, $corps]) {
            $reponse = $this->asTenant($this->admin)->{$verbe}($chemin, $corps);
            $reponse->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
            self::assertNotSame($introuvable, $reponse->json(), "$verbe $chemin : un refus motivé, pas « introuvable »");
        }

        self::assertSame(0, DB::table('regles_de_passage')->where('classe_id', $miroir->id)->count());
        self::assertSame(0, DB::table('decisions_de_passage')->where('classe_id', $miroir->id)->count());
    }

    /** @param  array<string, mixed>  $corps */
    private function corriger(User $eleve, array $corps): TestResponse
    {
        return $this->asTenant($this->admin)->putJson("/api/classes/{$this->classe->id}/passages/{$eleve->id}", $corps);
    }

    /** Trois matières de coefficient 1 ; Awa 13, Binta 8,67 (3 sous 10), Coffi 9 (2 sous 10), Dédé 5, Éli sans note. */
    private function classeNotee(): void
    {
        $this->ecole = $this->ecole();
        $this->admin = $this->membre($this->ecole, 'admin');
        $this->classe = $this->classe($this->ecole, '6e A');
        $matieres = [];
        foreach (['Maths', 'Physique', 'Chimie'] as $libelle) {
            $matiere = $this->matiere($this->ecole, $libelle);
            DB::table('classe_matiere')->insert(['classe_id' => $this->classe->id, 'matiere_id' => $matiere->id, 'institution_id' => $this->ecole->id, 'coefficient' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $matieres[] = $this->evaluation($matiere->id, $libelle);
        }
        $notes = ['Awa' => [14, 12, 13], 'Binta' => [9, 9, 8], 'Coffi' => [12, 8, 7], 'Dédé' => [5, 6, 4], 'Éli' => []];
        foreach ($notes as $nom => $valeurs) {
            $this->eleves[$nom] = $this->membre($this->ecole, 'etudiant');
            $this->eleves[$nom]->forceFill(['name' => $nom])->save();
            $this->inscrire($this->eleves[$nom], $this->classe, 'actif');
            foreach ($valeurs as $i => $valeur) {
                EvaluationSubmission::factory()->create([
                    'evaluation_id' => $matieres[$i]->id, 'institution_id' => $this->ecole->id, 'student_id' => $this->eleves[$nom]->id,
                    'klassci_etudiant_id' => null, 'attempt' => 1, 'status' => 'soumis', 'feedback' => null,
                    'score' => $valeur, 'note_sur_20' => $valeur, 'submitted_at' => now(),
                ]);
            }
        }
    }

    private function evaluation(int $matiereId, string $titre): Evaluation
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $this->ecole->id, 'classe_id' => $this->classe->id, 'matiere_id' => $matiereId,
            'klassci_classe_id' => null, 'klassci_matiere_id' => null, 'klassci_evaluation_id' => null, 'klassci_enseignant_id' => null,
            'titre' => $titre, 'coefficient' => '1.00', 'is_published' => true, 'show_results' => true,
            'status' => 'terminee', 'date_evaluation' => now()->subDays(10), 'duree_minutes' => 60,
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->id, 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }
}
