<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\TrainingSession;
use Database\Factories\TrainingSessionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\PreparesClasseWithCode;
use Tests\TestCase;

/**
 * #1208, point 2 — l'inscription par code respecte la Période de la classe.
 *
 * - La Période doit être PUBLIÉE : une Période en brouillon n'ouvre pas les codes (décision du
 *   user, 20/11). Elle reste rattachable (`RattacherUneClasseAUnePeriodeTest`).
 * - Aujourd'hui doit être dans la fenêtre `enrollment_opens_at ≤ aujourd'hui ≤
 *   enrollment_closes_at` : bornes INCLUSIVES, au jour, en UTC. Une borne absente ne ferme rien.
 *
 * Refus : le MÊME 404 qu'un code inconnu, par les deux portes (ADR-803-03, #897). Un motif dirait
 * à un inconnu qu'un code existe et ouvrira bientôt une classe.
 */
final class FenetreDInscriptionParCodeTest extends TestCase
{
    use ActsAsTenantUser;
    use PreparesClasseWithCode;
    use RefreshDatabase;

    /** @return iterable<string, array{0: string}> */
    public static function portes(): iterable
    {
        yield 'porte anonyme' => ['anonyme'];
        yield 'porte authentifiée' => ['authentifiee'];
    }

    #[DataProvider('portes')]
    public function test_une_periode_publiee_dans_sa_fenetre_accepte_le_code(string $porte): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, TrainingSession::factory()->publiee()->inscriptionsOuvertes());

        $this->inscrire($porte, $ecole, $code)->assertSuccessful();
        self::assertSame(1, $classe->etudiants()->count());
    }

    #[DataProvider('portes')]
    public function test_avant_l_ouverture_le_code_est_refuse(string $porte): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, TrainingSession::factory()->publiee()->inscriptionsAVenir());

        $this->inscrire($porte, $ecole, $code)->assertNotFound();
        self::assertSame(0, $classe->etudiants()->count());
    }

    #[DataProvider('portes')]
    public function test_apres_la_fermeture_le_code_est_refuse(string $porte): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, TrainingSession::factory()->publiee()->inscriptionsCloses());

        $this->inscrire($porte, $ecole, $code)->assertNotFound();
        self::assertSame(0, $classe->etudiants()->count());
    }

    #[DataProvider('portes')]
    public function test_une_periode_en_brouillon_n_ouvre_pas_le_code_meme_dans_sa_fenetre(string $porte): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, TrainingSession::factory()->inscriptionsOuvertes());

        $this->inscrire($porte, $ecole, $code)->assertNotFound();
        self::assertSame(0, $classe->etudiants()->count());
    }

    /** Borne inclusive : le jour de l'ouverture, dès minuit (UTC). */
    public function test_le_jour_de_l_ouverture_le_code_est_accepte_des_minuit(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, TrainingSession::factory()->publiee()->fenetre(0, 5));
        $this->travelTo(today()->startOfDay());

        $this->inscrire('anonyme', $ecole, $code)->assertSuccessful();
    }

    /** Borne inclusive : le jour de la fermeture, jusqu'à 23:59:59 (UTC). */
    public function test_le_jour_de_la_fermeture_le_code_est_accepte_jusqu_a_minuit(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, TrainingSession::factory()->publiee()->fenetre(-5, 0));
        $this->travelTo(today()->endOfDay());

        $this->inscrire('anonyme', $ecole, $code)->assertSuccessful();
    }

    /** Une borne absente ne ferme rien : une Période publiée sans aucune date reçoit. */
    public function test_une_periode_publiee_sans_dates_accepte_le_code(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, TrainingSession::factory()->publiee()->sansDates());

        $this->inscrire('anonyme', $ecole, $code)->assertSuccessful();
    }

    /** Le refus est celui d'un code inconnu, mot pour mot : rien ne dit que la fenêtre existe. */
    public function test_le_refus_hors_fenetre_est_celui_d_un_code_inconnu(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, TrainingSession::factory()->publiee()->inscriptionsAVenir());

        $horsFenetre = $this->inscrire('anonyme', $ecole, $code);
        $inconnu = $this->inscrire('anonyme', $ecole, 'ZZZZZZ');

        $horsFenetre->assertNotFound();
        self::assertSame($inconnu->json(), $horsFenetre->json());
    }

    /** Témoin : en école KLASSCI, le résolveur refuse tout code avant de lire une Période. */
    public function test_en_ecole_klassci_le_code_reste_refuse(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->rattacher($classe, $ecole, TrainingSession::factory()->publiee()->inscriptionsOuvertes());
        $ecole->forceFill(['mode' => InstitutionMode::Klassci])->save();

        $this->inscrire('anonyme', $ecole, $code)->assertNotFound();
        self::assertSame(0, $classe->etudiants()->count());
    }

    // ───────────────────────── fixtures

    /** @param TrainingSessionFactory $fabrique */
    private function rattacher(Classe $classe, Institution $ecole, $fabrique): void
    {
        $periode = $fabrique->create(['institution_id' => $ecole->id]);
        $classe->forceFill(['training_session_id' => $periode->id])->save();
    }

    /** @return TestResponse<JsonResponse> */
    private function inscrire(string $porte, Institution $ecole, string $code): TestResponse
    {
        if ($porte === 'authentifiee') {
            return $this->asTenant($this->apprenant($ecole))->postJson('/api/me/inscriptions', ['code' => $code]);
        }

        return $this->postJson('/api/inscriptions', [
            'code' => $code,
            'nom' => 'Awa Kabore',
            'email' => 'awa@test.ci',
            'password' => 'mot-de-passe-choisi',
            'password_confirmation' => 'mot-de-passe-choisi',
        ], ['X-Institution' => (string) $ecole->slug]);
    }
}
