<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Institution;
use App\Models\User;
use App\Models\UserClass;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * `GET /evaluations/{id}` pour un apprenant : l'écran de passage de l'élève
 * (`useTakeEvaluation`) s'ouvre pour EXACTEMENT ce que sa propre liste,
 * `/evaluations/student`, lui propose. Sinon 404, sans distinguer « n'existe
 * pas » de « pas la vôtre ».
 *
 * L'état de PRODUCTION est reproduit : l'élève est connecté (jeton KLASSCI)
 * mais n'a AUCUNE ligne `user_classes` (#969). Sa classe vient de la source
 * vive, `me/dashboard`, la même que celle de la liste (LearnerEvaluationScope).
 * KLASSCI est simulé, une closure par requête.
 *
 * Le personnel garde sa vue, corrigé compris pour qui rédige.
 *
 * @see app/Services/Evaluation/Student/LearnerEvaluationScope.php
 */
final class EvaluationLearnerScopeTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const CLASSE = 4242;

    private const AUTRE_CLASSE = 4343;

    private Institution $ecole;

    /**
     * La propriété qui protège l'écran de passage : le détail s'ouvre pour
     * EXACTEMENT les évaluations que la liste de l'élève lui propose. Ni moins
     * (l'écran casserait), ni plus (la fuite).
     */
    public function test_le_detail_s_ouvre_pour_exactement_la_liste_de_l_eleve(): void
    {
        $eleve = $this->eleveConnecte();
        $this->klassciRend(self::CLASSE);

        $candidates = [];
        foreach (['planifiee', 'en_cours', 'terminee'] as $statut) {
            $candidates[] = $this->evaluation(self::CLASSE, ['status' => $statut]);
        }
        $candidates[] = $this->evaluation(self::CLASSE, ['status' => 'brouillon', 'is_published' => false]);
        $candidates[] = $this->evaluation(self::CLASSE, ['status' => 'en_cours', 'is_published' => false]);
        $candidates[] = $this->evaluation(self::CLASSE, ['status' => 'brouillon']);
        $candidates[] = $this->evaluation(self::CLASSE, ['status' => 'en_cours'], avecQuestion: false);
        $candidates[] = $this->evaluation(self::AUTRE_CLASSE, ['status' => 'en_cours']);

        self::assertSame(0, UserClass::query()->where('user_id', $eleve->id)->count(), 'état de production : aucun cache');

        $liste = array_column($this->asTenant($eleve)->getJson('/api/evaluations/student')->assertOk()->json('data'), 'id');
        sort($liste);

        $ouvertes = [];
        foreach ($candidates as $evaluation) {
            $statut = $this->asTenant($eleve)->getJson("/api/evaluations/{$evaluation->id}")->status();
            self::assertContains($statut, [200, 404], "évaluation {$evaluation->id}");
            if ($statut === 200) {
                $ouvertes[] = $evaluation->id;
            }
        }
        sort($ouvertes);

        self::assertCount(3, $liste, 'la liste : trois statuts visibles, publiés, avec questions, de sa classe');
        self::assertSame($liste, $ouvertes);
    }

    public function test_l_eleve_compose_avec_les_questions_sans_le_corrige(): void
    {
        $eleve = $this->eleveConnecte();
        $this->klassciRend(self::CLASSE);
        $evaluation = $this->evaluation(self::CLASSE, ['status' => 'en_cours']);

        $reponse = $this->asTenant($eleve)->getJson("/api/evaluations/{$evaluation->id}")->assertOk();

        self::assertCount(1, $reponse->json('data.questions'));
        self::assertStringNotContainsString('LA-REPONSE-SECRETE', (string) $reponse->getContent());
    }

    /** @return iterable<string, array{0: string}> */
    public static function horsPersonnel(): iterable
    {
        // '' : le rôle absent que la base admet (`users.role` est NOT NULL).
        foreach (['etudiant', 'Etudiant', 'eleve', 'SuperAdmin', 'Supradmin', 'ADMIN', 'Enseignant', ''] as $role) {
            yield "« $role »" => [$role];
        }
    }

    /**
     * Hors personnel, avec un jeton et une classe : un brouillon, une
     * évaluation non publiée, une évaluation sans question et celle d'une autre
     * classe sont introuvables. Le corps ne dit rien de l'évaluation.
     */
    #[DataProvider('horsPersonnel')]
    public function test_hors_personnel_le_detail_hors_de_sa_liste_est_introuvable(string $role): void
    {
        $compte = $this->eleveConnecte($role);
        $this->klassciRend(self::CLASSE);

        foreach ([
            'brouillon' => $this->evaluation(self::CLASSE, ['status' => 'brouillon', 'is_published' => false]),
            'non publiée' => $this->evaluation(self::CLASSE, ['status' => 'en_cours', 'is_published' => false]),
            'statut brouillon publiée' => $this->evaluation(self::CLASSE, ['status' => 'brouillon']),
            'sans question' => $this->evaluation(self::CLASSE, ['status' => 'en_cours'], avecQuestion: false),
            'autre classe' => $this->evaluation(self::AUTRE_CLASSE, ['status' => 'en_cours']),
        ] as $cas => $evaluation) {
            $reponse = $this->asTenant($compte)->getJson("/api/evaluations/{$evaluation->id}");
            self::assertSame(404, $reponse->status(), $cas);
            self::assertStringNotContainsString('Algebre', (string) $reponse->getContent(), $cas);
        }
    }

    public function test_sans_jeton_klassci_l_eleve_n_ouvre_rien(): void
    {
        $eleve = $this->eleveConnecte(jeton: null);
        $this->klassciRend(self::CLASSE);
        $evaluation = $this->evaluation(self::CLASSE, ['status' => 'en_cours']);

        $this->asTenant($eleve)->getJson("/api/evaluations/{$evaluation->id}")->assertNotFound();
    }

    public function test_sans_classe_chez_klassci_l_eleve_n_ouvre_rien(): void
    {
        $eleve = $this->eleveConnecte();
        $this->klassciRend(null);
        $evaluation = $this->evaluation(self::CLASSE, ['status' => 'en_cours']);

        $this->asTenant($eleve)->getJson("/api/evaluations/{$evaluation->id}")->assertNotFound();
    }

    /** Panne de KLASSCI : la classe n'est pas vérifiable, refus explicite (503), jamais un accès. */
    public function test_klassci_indisponible_rend_503_et_rien_de_l_evaluation(): void
    {
        $eleve = $this->eleveConnecte();
        $evaluation = $this->evaluation(self::CLASSE, ['status' => 'en_cours']);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow(KlassciUnavailableException::circuitOpen(30));
        });

        $reponse = $this->asTenant($eleve)->getJson("/api/evaluations/{$evaluation->id}");

        self::assertSame(503, $reponse->status());
        self::assertStringNotContainsString('Algebre', (string) $reponse->getContent());
    }

    /**
     * Jeton KLASSCI expiré : KLASSCI répond 401, et le client HTTP lève
     * `RuntimeException('Erreur API KLASSCI: 401', 401)`. Le détail le relaie
     * en 401, comme la liste de l'élève (renderKlassciFailure), et non en 500.
     */
    public function test_un_jeton_klassci_expire_rend_401_comme_la_liste(): void
    {
        $eleve = $this->eleveConnecte();
        $evaluation = $this->evaluation(self::CLASSE, ['status' => 'en_cours']);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow(new \RuntimeException('Erreur API KLASSCI: 401', 401));
        });

        $detail = $this->asTenant($eleve)->getJson("/api/evaluations/{$evaluation->id}");
        $liste = $this->asTenant($eleve)->getJson('/api/evaluations/student');

        self::assertSame(401, $detail->status());
        self::assertSame($liste->status(), $detail->status(), 'même réponse que la liste de l\'élève');
        self::assertStringNotContainsString('Algebre', (string) $detail->getContent());
    }

    /**
     * La source unique porte le tenant elle-même. Le test se place
     * DÉLIBÉRÉMENT sans tenant résolu (`Sanctum::actingAs` n'émet aucun jeton,
     * donc ResolveInstitution ne pose rien) : le scope global est alors ouvert,
     * et seul le filtre explicite de LearnerEvaluationScope::visibleIn tient.
     * Avec un vrai jeton, le scope global masquerait déjà l'autre école, et ce
     * filtre ne serait pas prouvé.
     */
    public function test_une_autre_ecole_au_meme_identifiant_de_classe_reste_invisible(): void
    {
        $eleve = $this->eleveConnecte();
        $ecoleDeLEleve = $this->ecole;
        $this->ecole = Institution::factory()->create();
        $ailleurs = $this->evaluation(self::CLASSE, ['status' => 'en_cours']);
        $this->ecole = $ecoleDeLEleve;
        $this->klassciRend(self::CLASSE);
        Sanctum::actingAs($eleve);

        $this->getJson("/api/evaluations/{$ailleurs->id}")->assertNotFound();
        $liste = array_column($this->getJson('/api/evaluations/student')->assertOk()->json('data'), 'id');
        self::assertNotContains($ailleurs->id, $liste);
    }

    public function test_dans_une_ecole_autonome_une_evaluation_locale_n_a_pas_de_porte(): void
    {
        $eleve = $this->eleveConnecte(ecoleAutonome: true);
        $this->klassciRend(self::CLASSE);
        $locale = $this->evaluation(null, ['status' => 'en_cours']);

        $this->asTenant($eleve)->getJson("/api/evaluations/{$locale->id}")->assertNotFound();
    }

    /** Le personnel ne passe pas par la porte : il lit un brouillon, corrigé compris pour l'enseignant. */
    public function test_l_enseignant_lit_un_brouillon_avec_son_corrige(): void
    {
        $enseignant = $this->eleveConnecte('enseignant');
        $brouillon = $this->evaluation(self::CLASSE, ['status' => 'brouillon', 'is_published' => false]);

        $reponse = $this->asTenant($enseignant)->getJson("/api/evaluations/{$brouillon->id}")->assertOk();

        self::assertStringContainsString('LA-REPONSE-SECRETE', (string) $reponse->getContent());
    }

    /**
     * Compte connecté par KLASSCI, comme en production : jeton présent, AUCUNE
     * ligne `user_classes`, aucun pivot.
     */
    private function eleveConnecte(string $role = 'etudiant', ?string $jeton = 'jeton-eleve', bool $ecoleAutonome = false): User
    {
        $this->disableKlassciMiddleware();
        $this->ecole = $ecoleAutonome ? Institution::factory()->standalone()->create() : Institution::factory()->create();

        return User::factory()->create([
            'institution_id' => $this->ecole->id,
            'role' => $role,
            'klassci_id' => $ecoleAutonome ? null : 910001,
            'klassci_token' => $jeton,
        ]);
    }

    /** @param array<string, mixed> $attributs */
    private function evaluation(?int $klassciClasseId, array $attributs, bool $avecQuestion = true): Evaluation
    {
        $evaluation = Evaluation::factory()->create($attributs + [
            'institution_id' => $this->ecole->id,
            'klassci_classe_id' => $klassciClasseId,
            'titre' => 'Algebre, controle',
            'is_published' => true,
        ]);
        if ($avecQuestion) {
            EvaluationQuestion::factory()->create([
                'evaluation_id' => $evaluation->id,
                'institution_id' => $this->ecole->id,
                'type' => 'qcm',
                'correct_answers' => ['LA-REPONSE-SECRETE'],
                'points' => 20,
            ]);
        }

        return $evaluation;
    }

    /** KLASSCI rend la classe de l'élève (ou aucune), et aucune évaluation distante. */
    private function klassciRend(?int $classe): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($classe): void {
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(static function (string $jeton, string $endpoint) use ($classe): array {
                    if ($endpoint === 'me/dashboard') {
                        return ['data' => $classe === null ? [] : ['classe' => ['id' => $classe, 'name' => 'L1 Informatique']]];
                    }

                    return ['data' => []];
                });
        });
    }
}
