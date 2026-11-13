<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Evaluation\Student\LocalMatieresDesNotes;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * D72, E4a : « Mes notes » (`GET /my-grades`) dans les deux types d'école, et C30.
 *
 * Avant : un élève sans `klassci_id` recevait 401 avant toute lecture, donc tout
 * élève d'une école créée depuis le LMS. Et les notes se regroupaient par
 * `klassci_matiere_id ?? 0` : dans cette école, toutes les matières tombaient sous
 * la clé 0, avec le nom de la première venue.
 *
 * C30, décision de l'utilisateur (règle A) : `show_results` décide, comme sur les
 * autres routes. Une évaluation qui cache ses résultats GARDE sa ligne dans « Mes
 * notes », mais SANS la clé `note` (la forme de `ResultatsVisibles` sur la liste),
 * et elle est exclue des moyennes. L'élève sait qu'elle existe sans en voir la note.
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class EleveVoitSesNotesTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    // ───────────────────────── école créée depuis le LMS

    public function test_ecole_du_lms_les_notes_se_regroupent_par_matiere_locale_sans_klassci(): void
    {
        [$ecole, $eleve, $classe] = $this->eleveInscrit();
        $maths = $this->matiere($ecole, 'Maths');
        $physique = $this->matiere($ecole, 'Physique');
        $this->note($this->evaluation($classe, $maths, 'Maths 1', '2.00'), $eleve, '12.00');
        $this->note($this->evaluation($classe, $maths, 'Maths 2', '1.00'), $eleve, '18.00');
        $this->note($this->evaluation($classe, $physique, 'Physique 1', '1.00'), $eleve, '10.00');
        $eleve->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $this->aucunAppelKlassci();

        $reponse = $this->asTenant($eleve)->getJson('/api/my-grades');

        $this->assertMatchesOpenApiResponse($reponse, '/my-grades', 'get');
        $reponse->assertOk()
            ->assertJsonPath('data.total_matieres', 2)
            ->assertJsonPath('data.total_evaluations', 3)
            ->assertJsonPath('data.moyenne_generale', 12);
        self::assertSame([
            [$maths->getKey(), 'Maths', 14, 2],
            [$physique->getKey(), 'Physique', 10, 1],
        ], $this->matieres($reponse->json('data.matieres')));
    }

    /** C30 : `show_results` à faux, la ligne reste, sans sa note, et hors des moyennes. */
    public function test_ecole_du_lms_une_evaluation_aux_resultats_caches_garde_sa_ligne_sans_note(): void
    {
        [$ecole, $eleve, $classe] = $this->eleveInscrit();
        $maths = $this->matiere($ecole, 'Maths');
        $this->note($this->evaluation($classe, $maths, 'Visible', '1.00'), $eleve, '10.00');
        $this->note($this->evaluation($classe, $maths, 'Cachée', '1.00', ['show_results' => false]), $eleve, '20.00');
        $this->aucunAppelKlassci();

        $reponse = $this->asTenant($eleve)->getJson('/api/my-grades');

        $this->assertMatchesOpenApiResponse($reponse, '/my-grades', 'get');
        $reponse->assertOk()
            ->assertJsonPath('data.total_evaluations', 2)
            ->assertJsonPath('data.matieres.0.total_evaluations', 2)
            ->assertJsonPath('data.matieres.0.moyenne', 10)
            ->assertJsonPath('data.moyenne_generale', 10);
        $lignes = array_column((array) $reponse->json('data.matieres.0.evaluations'), null, 'titre');
        self::assertEqualsCanonicalizing(['Visible', 'Cachée'], array_keys($lignes), 'la ligne cachée a disparu');
        self::assertSame('10.00', $lignes['Visible']['note'] ?? null);
        self::assertArrayNotHasKey('note', $lignes['Cachée']);
        self::assertStringNotContainsString('20.00', (string) $reponse->getContent());
    }

    /**
     * Les noms des matières sont bornés à l'établissement de l'élève. Prouvé sans
     * tenant, sur la source elle-même : en HTTP, le scope global masquerait la preuve.
     */
    public function test_le_nom_d_une_matiere_d_une_autre_ecole_ne_nomme_pas_la_note(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $ailleurs = $this->matiere($this->ecole(), 'Matière étrangère');
        $chezSoi = $this->matiere($ecole, 'Maths');

        $noms = app(LocalMatieresDesNotes::class)->noms($eleve, [(int) $ailleurs->getKey(), (int) $chezSoi->getKey()]);

        self::assertSame([(int) $chezSoi->getKey() => 'Maths'], $noms);
    }

    // ───────────────────────── école créée depuis KLASSCI

    public function test_ecole_klassci_les_noms_viennent_de_klassci_et_le_regroupement_de_l_identite_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->eleveKlassci($ecole);
        $this->klassciNomme([['id' => 31, 'nom' => 'Mathématiques KLASSCI']]);
        $this->note($this->evaluationKlassci($ecole, 31, 'Devoir 1'), $eleve, '14.00');
        $this->note($this->evaluationKlassci($ecole, 31, 'Devoir 2'), $eleve, '16.00');

        $reponse = $this->asTenant($eleve)->getJson('/api/my-grades');

        $this->assertMatchesOpenApiResponse($reponse, '/my-grades', 'get');
        $reponse->assertOk();
        self::assertSame([[31, 'Mathématiques KLASSCI', 15, 2]], $this->matieres($reponse->json('data.matieres')));
    }

    /**
     * C30, école KLASSCI : une matière dont la seule note est cachée garde sa ligne,
     * sans note. Sa moyenne n'a aucune mesure : `null`, jamais un 0 fabriqué ; elle
     * n'entre pas dans la moyenne générale.
     */
    public function test_ecole_klassci_une_evaluation_aux_resultats_caches_garde_sa_ligne_hors_des_moyennes(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->eleveKlassci($ecole);
        $this->klassciNomme([['id' => 31, 'nom' => 'Maths'], ['id' => 32, 'nom' => 'Physique']]);
        $this->note($this->evaluationKlassci($ecole, 31, 'Cachée', ['show_results' => false]), $eleve, '20.00');
        $this->note($this->evaluationKlassci($ecole, 32, 'Visible'), $eleve, '12.00');

        $reponse = $this->asTenant($eleve)->getJson('/api/my-grades');

        $this->assertMatchesOpenApiResponse($reponse, '/my-grades', 'get');
        $reponse->assertOk()
            ->assertJsonPath('data.total_evaluations', 2)
            ->assertJsonPath('data.moyenne_generale', 12);
        self::assertSame([[31, 'Maths', null, 1], [32, 'Physique', 12, 1]], $this->matieres($reponse->json('data.matieres')));
        self::assertArrayNotHasKey('note', (array) $reponse->json('data.matieres.0.evaluations.0'));
    }

    public function test_ecole_klassci_sans_identite_klassci_reste_un_401(): void
    {
        $eleve = $this->membre($this->ecole(InstitutionMode::Klassci), 'etudiant');
        $this->disableKlassciMiddleware();

        $this->asTenant($eleve)->getJson('/api/my-grades')
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'klassci_token_missing');
    }

    // ───────────────────────── règle 3

    /** Laravel garde le contrôleur sur l'objet Route : la source se reçoit par la MÉTHODE. */
    public function test_la_meme_route_sert_une_ecole_klassci_puis_une_ecole_du_lms_puis_klassci(): void
    {
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $eleveKlassci = $this->eleveKlassci($klassci);
        $this->klassciNomme([['id' => 31, 'nom' => 'Maths KLASSCI']]);
        $this->note($this->evaluationKlassci($klassci, 31, 'Devoir'), $eleveKlassci, '14.00');
        [$ecole, $eleveLocal, $classe] = $this->eleveInscrit();
        $maths = $this->matiere($ecole, 'Maths locales');
        $this->note($this->evaluation($classe, $maths, 'Local', '1.00'), $eleveLocal, '12.00');

        foreach ([[$eleveKlassci, 'Maths KLASSCI'], [$eleveLocal, 'Maths locales'], [$eleveKlassci, 'Maths KLASSCI']] as [$eleve, $nom]) {
            $this->asTenant($eleve)->getJson('/api/my-grades')->assertOk()->assertJsonPath('data.matieres.0.matiere_nom', $nom);
        }
    }

    // ───────────────────────── harnais

    /**
     * @param  mixed  $matieres
     * @return list<array{mixed, mixed, mixed, mixed}>
     */
    private function matieres(mixed $matieres): array
    {
        self::assertIsArray($matieres);

        return array_values(array_map(static fn (array $m): array => [$m['matiere_id'], $m['matiere_nom'], $m['moyenne'], $m['total_evaluations']], $matieres));
    }

    /** @return array{Institution, User, Classe} */
    private function eleveInscrit(): array
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($eleve, $classe, 'actif');

        return [$ecole, $eleve, $classe];
    }

    private function eleveKlassci(Institution $ecole): User
    {
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['klassci_id' => 501, 'klassci_token' => 'jeton'])->save();
        $this->disableKlassciMiddleware();

        return $eleve;
    }

    /** @param  array<string, mixed>  $surcharges */
    private function evaluation(Classe $classe, Matiere $matiere, string $titre, string $coefficient, array $surcharges = []): Evaluation
    {
        // Comme `LocalEvaluationProgramming` l'écrit : ids locaux, aucune identité KLASSCI.
        return $this->evaluationAvecQcm(array_merge([
            'institution_id' => $classe->institution_id,
            'classe_id' => $classe->getKey(),
            'matiere_id' => $matiere->getKey(),
            'matiere_nom' => $matiere->libelle,
            'klassci_evaluation_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'klassci_enseignant_id' => null,
            'titre' => $titre,
            'coefficient' => $coefficient,
        ], $surcharges));
    }

    /** @param  array<string, mixed>  $surcharges */
    private function evaluationKlassci(Institution $ecole, int $matiereKlassci, string $titre, array $surcharges = []): Evaluation
    {
        return $this->evaluationAvecQcm(array_merge([
            'institution_id' => $ecole->getKey(),
            'klassci_matiere_id' => $matiereKlassci,
            'matiere_id' => null,
            'matiere_nom' => 'Nom gardé sur la ligne',
            'titre' => $titre,
            'coefficient' => '1.00',
        ], $surcharges));
    }

    /** @param  array<string, mixed>  $attributs */
    private function evaluationAvecQcm(array $attributs): Evaluation
    {
        $evaluation = Evaluation::factory()->create(array_merge([
            'is_published' => true,
            'show_results' => true,
            'status' => 'terminee',
            'date_evaluation' => now()->subDay(),
            'duree_minutes' => 60,
        ], $attributs));
        EvaluationQuestion::factory()->create(['evaluation_id' => $evaluation->getKey(), 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);

        return $evaluation;
    }

    private function note(Evaluation $evaluation, User $eleve, string $note): EvaluationSubmission
    {
        return EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->getKey(),
            'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve->getKey(),
            'klassci_etudiant_id' => $eleve->klassci_id,
            'attempt' => 1,
            'status' => 'soumis',
            'feedback' => null,
            'score' => $note,
            'note_sur_20' => $note,
            'submitted_at' => now(),
        ]);
    }

    /** @param  list<array{id: int, nom: string}>  $matieres */
    private function klassciNomme(array $matieres): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($matieres): void {
            $mock->shouldReceive('getMatieres')->with('jeton')->andReturn(['success' => true, 'data' => $matieres]);
        });
    }

    /** Aucun appel à KLASSCI : ni requête sortante, ni méthode du mandataire. */
    private function aucunAppelKlassci(): void
    {
        Http::preventStrayRequests();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getMatieres', 'requestWithUserToken');
        });
    }
}
