<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Lire les notes d'une évaluation exige d'en être propriétaire.
 *
 * Deux lectures livrent les notes NOMINATIVES d'une évaluation — les résultats
 * par classe et la liste des soumissions — et aucune ne vérifiait à qui
 * appartient l'évaluation. Le rôle suffisait : tout enseignant du tenant lisait
 * les notes de tout collègue. `TeacherEvaluationViewService:49` le disait déjà
 * en toutes lettres — « conservé pour audit log + FUTURE autorisation ».
 *
 * ## Les deux routes ensemble, jamais l'une sans l'autre
 *
 * Elles exposent la MÊME donnée. N'en fermer qu'une laisserait la fuite
 * accessible en changeant d'URL : ce ne serait pas un correctif partiel, ce
 * serait l'illusion d'un correctif.
 *
 * ## Ce qui ne doit PAS se refermer
 *
 * Le coordinateur et l'admin gardent leur accès : la route le leur accorde
 * (`role:enseignant,coordinateur,superAdmin`) et {@see \App\Services\Evaluation\Teacher\TeacherEvaluationViewService::preview()}
 * écrit déjà une politique de supervision pour le coordinateur. Une règle qui
 * les exclurait serait une régression, pas un durcissement — d'où les cas
 * verts ci-dessous, qui sont des gardes au même titre que les rouges.
 *
 * ## Jeton porteur RÉEL
 *
 * `Sanctum::actingAs` n'émet pas de Bearer : `ResolveInstitution` ne pose alors
 * aucun tenant et `BelongsToInstitution` saute son scope (fail-open documenté).
 * Un test d'isolation écrit ainsi passerait sans rien prouver (#709, #524).
 *
 * @see app/Http/Requests/Concerns/ChecksEvaluationOwnership.php
 */
final class EvaluationResultsOwnershipTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ENSEIGNANT_PROPRIETAIRE = 4242;
    private const ENSEIGNANT_TIERS = 9999;

    private Institution $institution;
    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        // AUCUN appel ne doit sortir. `InstitutionFactory` pose un
        // `fake()->url()` : sans ce faux, `results-by-class` interrogeait pour de
        // bon un domaine reel, et le relais de statut (#270) renvoyait fidelement
        // ce que ce site repondait. Un 403 venu d'un inconnu faisait echouer
        // `assertNotSame(403)` au hasard — un test de securite intermittent, donc
        // un test que l'on finit par ignorer.
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => ['classe' => ['id' => 55, 'nom' => 'B2 COM'], 'etudiants' => []],
        ])]);

        $this->institution = Institution::factory()->create();
        $this->evaluation = Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => self::ENSEIGNANT_PROPRIETAIRE,
            'is_published' => true,
        ]);
    }

    private function utilisateur(string $role, ?int $klassciEnseignantId): User
    {
        return User::factory()->for($this->institution)->create([
            'role' => $role,
            'klassci_enseignant_id' => $klassciEnseignantId,
            'klassci_token' => 'jeton',
        ]);
    }

    /** @return array<string, TestResponse> Les deux lectures, pour le même acteur. */
    private function lesDeuxLectures(User $acteur): array
    {
        return [
            'results-by-class' => $this->asTenant($acteur)
                ->getJson("/api/evaluations/{$this->evaluation->id}/results-by-class"),
            'submissions' => $this->asTenant($acteur)
                ->getJson("/api/evaluations/{$this->evaluation->id}/submissions"),
        ];
    }

    /**
     * Compare les DEUX routes d'un seul coup.
     *
     * Une boucle d'assertions s'arrête à la première qui tombe : la seconde
     * route ne serait jamais evaluée, et l'on croirait l'avoir couverte. En
     * assertant sur la carte complète, l'echec nomme les deux etats reels.
     */
    private function assertToutesLesLectures(User $acteur, int $attendu): void
    {
        $statuts = array_map(
            static fn (TestResponse $r): int => $r->status(),
            $this->lesDeuxLectures($acteur),
        );

        self::assertSame(
            ['results-by-class' => $attendu, 'submissions' => $attendu],
            $statuts,
        );
    }

    public function test_un_enseignant_ne_lit_pas_les_notes_d_une_evaluation_d_un_collegue(): void
    {
        $tiers = $this->utilisateur('enseignant', self::ENSEIGNANT_TIERS);

        $this->assertToutesLesLectures($tiers, 403);
    }

    public function test_un_enseignant_sans_identite_klassci_est_ferme(): void
    {
        // L'évaluation est ORPHELINE À DESSEIN. Sur une évaluation possédée, un
        // compte sans identité serait déjà rejeté par la simple comparaison des
        // identifiants : le test passerait sans rien dire de la règle qu'il
        // prétend garder. C'est précisément ce que la falsification a montré —
        // retirer la garde `!== null` laissait ce test vert.
        //
        // Orpheline, `null === null` vaut vrai : sans garde explicite, l'absence
        // d'identité DEVIENT une preuve de propriété. C'est la leçon de
        // TeacherOwnershipScope, ici rendue exécutable.
        $this->evaluation->forceFill(['klassci_enseignant_id' => null])->save();

        $sansIdentite = $this->utilisateur('enseignant', null);

        $this->assertToutesLesLectures($sansIdentite, 403);
    }

    public function test_le_proprietaire_lit_ses_propres_notes(): void
    {
        $proprietaire = $this->utilisateur('enseignant', self::ENSEIGNANT_PROPRIETAIRE);

        foreach ($this->lesDeuxLectures($proprietaire) as $route => $reponse) {
            self::assertNotSame(403, $reponse->status(), "La route {$route} a fermé le propriétaire.");
        }
    }

    public function test_le_coordinateur_garde_son_acces_de_supervision(): void
    {
        $coordinateur = $this->utilisateur('coordinateur', null);

        foreach ($this->lesDeuxLectures($coordinateur) as $route => $reponse) {
            self::assertNotSame(403, $reponse->status(), "La route {$route} a fermé le coordinateur.");
        }
    }

    public function test_l_admin_d_etablissement_garde_son_acces(): void
    {
        $admin = $this->utilisateur('superAdmin', null);

        foreach ($this->lesDeuxLectures($admin) as $route => $reponse) {
            self::assertNotSame(403, $reponse->status(), "La route {$route} a fermé l'admin.");
        }
    }
}
