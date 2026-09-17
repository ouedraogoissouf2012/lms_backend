<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Teacher;

use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Source du roster de « Notes et Résultats » — le geste #669 appliqué au
 * dernier appelant resté en arrière.
 *
 * KLASSCI applique une autorisation PAR CLASSE sur `classes/{id}/etudiants` et
 * répond 403 « Accès non autorisé à cette classe » à TOUS les rôles, superAdmin
 * compris. L'enveloppe `classes/{id}`, elle, répond 200 et porte déjà le roster
 * (mesuré dans #669 : 17 classes, 210 étudiants listés pour 210 déclarés).
 * `ClasseDetailsQueryService` a cessé d'appeler l'endpoint interdit ;
 * `TeacherEvaluationResultsService` y est resté, et son `catch (Throwable)`
 * maison présentait ce 403 comme un 500.
 *
 * ## Pourquoi `Http::fake` et non un double de `KlassciProxyService`
 *
 * C'est l'URL réellement émise qui est en cause : un `shouldReceive()` sur un
 * nom de méthode ne la voit pas. C'est exactement ce qui a laissé
 * {@see EvaluationResultsCorrectnessTest} vert pendant que la production rendait
 * 500 — il stubbait `getClasseEtudiants()`, donc il POSTULAIT que l'endpoint
 * interdit répondait. Le faux ci-dessous reproduit au contraire le comportement
 * amont mesuré : 403 sur le roster, 200 sur l'enveloppe.
 *
 * @see app/Services/Evaluation/Teacher/TeacherEvaluationResultsService.php
 * @see tests/Unit/Services/Classe/ClasseDetailsEnvelopeSourceTest.php (même geste, #669)
 */
final class EvaluationResultsRosterSourceTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const BASE_URL = 'https://ecole.klassci.test/api/lms';
    private const CLASSE_ID = 55;

    private User $teacher;
    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create([
            'klassci_api_url' => self::BASE_URL,
            'is_active' => true,
        ]);
        app(TenantManager::class)->set($institution);

        // État fidèle à la production : au login KLASSCI, le synchronizer écrit
        // le jeton ET l'URL de tenant (KlassciUserSynchronizer::buildCommonData).
        // Sans `klassci_tenant_url`, la priorité 1 du résolveur ne joue pas et le
        // test échouerait sur la résolution d'URL — une raison étrangère à son objet.
        $this->teacher = User::factory()->teacher()->for($institution)->create([
            'klassci_token' => 'jeton-enseignant',
            'klassci_tenant_url' => self::BASE_URL,
        ]);
        $this->evaluation = Evaluation::factory()->create([
            'institution_id' => $institution->id,
            'klassci_classe_id' => self::CLASSE_ID,
            'is_published' => true,
        ]);
    }

    /**
     * KLASSCI tel qu'il se comporte réellement (#669) : l'endpoint roster refuse,
     * l'enveloppe accepte et porte les étudiants.
     *
     * L'ordre des motifs est porteur : `Http::fake` retient le PREMIER qui
     * correspond, et `classes/55*` engloberait `classes/55/etudiants`.
     */
    private function fakeKlassciReel(): void
    {
        Http::fake([
            self::BASE_URL.'/classes/'.self::CLASSE_ID.'/etudiants*' => Http::response(
                ['success' => false, 'message' => 'Accès non autorisé à cette classe'],
                403,
            ),
            self::BASE_URL.'/classes/'.self::CLASSE_ID.'*' => Http::response([
                'success' => true,
                'data' => [
                    'classe' => ['id' => self::CLASSE_ID, 'nom' => 'B2 COM'],
                    'etudiants' => [
                        ['id' => 700, 'nom' => 'Bede', 'prenom' => 'Abel', 'email' => 'a@ecole.test'],
                        ['id' => 701, 'nom' => 'Kone', 'prenom' => 'Awa', 'email' => 'b@ecole.test'],
                    ],
                ],
            ]),
            '*' => Http::response(['success' => true, 'data' => []]),
        ]);
    }

    /** L'enveloppe elle-même échoue avec `$statut`. */
    private function fakeEnveloppeEnEchec(int $statut): void
    {
        Http::fake([
            self::BASE_URL.'/classes/'.self::CLASSE_ID.'*' => Http::response(
                ['success' => false, 'message' => 'refus amont'],
                $statut,
            ),
            '*' => Http::response(['success' => true, 'data' => []]),
        ]);
    }

    /**
     * Jeton porteur RÉEL (#709) : `Sanctum::actingAs` n'émet pas de Bearer, donc
     * `ResolveInstitution` remet le tenant à zéro et la cible KLASSCI devient
     * introuvable — le test serait rouge pour une raison étrangère à son objet.
     */
    private function appeler(): TestResponse
    {
        return $this->asTenant($this->teacher)
            ->getJson("/api/evaluations/{$this->evaluation->id}/results-by-class");
    }

    public function test_le_roster_vient_de_l_enveloppe_malgre_le_refus_de_l_endpoint_dedie(): void
    {
        $this->fakeKlassciReel();

        $reponse = $this->appeler()->assertStatus(200);

        self::assertCount(2, $reponse->json('data.resultats'));
        self::assertSame(2, $reponse->json('data.statistiques.total_etudiants'));
    }

    public function test_l_endpoint_soumis_a_autorisation_par_classe_n_est_jamais_appele(): void
    {
        $this->fakeKlassciReel();

        $this->appeler()->assertStatus(200);

        Http::assertNotSent(
            static fn (Request $r): bool => str_contains($r->url(), '/classes/'.self::CLASSE_ID.'/etudiants')
        );
    }

    public function test_un_403_sur_l_enveloppe_est_relaye_et_non_maquille_en_500(): void
    {
        $this->fakeEnveloppeEnEchec(403);

        $this->appeler()->assertStatus(403);
    }

    public function test_une_indisponibilite_amont_est_relayee_en_503_et_non_en_500(): void
    {
        $this->fakeEnveloppeEnEchec(502);

        $this->appeler()->assertStatus(503);
    }
}
