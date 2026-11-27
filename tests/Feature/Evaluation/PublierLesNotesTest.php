<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use App\Models\Evaluation;
use App\Models\Institution;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\EvaluationCorrigee;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1699 : l'enseignant PUBLIE les notes d'une évaluation, par un geste distinct de leur saisie,
 * `PATCH /evaluations/{id}/release`. Sous vrai jeton, dans les deux types d'école.
 *
 * Avant : la seule voie était `show_results`, par la modification de l'évaluation, refusée en
 * 409 dès la première copie remise (`EvaluationStateService::isLocked`). Une note cachée
 * restait donc cachée POUR TOUJOURS. Et le commentaire de l'enseignant sortait même quand la
 * note était cachée (« ma copie », la liste).
 *
 * La publication ne touche ni aux questions ni aux copies : elle est permise sur une évaluation
 * verrouillée. Elle règle aussi l'ouverture du corrigé (`correction_release`, une date
 * seulement pour `at_date`). Les droits sont ceux de la modification
 * ({@see ChecksEvaluationOwnership}) : l'auteur ou un admin ; une
 * évaluation d'une autre école rend 403, comme `publish`.
 */
final class PublierLesNotesTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use EvaluationCorrigee;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/evaluations/{id}/release';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00:00', 'UTC'));
        $this->disableKlassciMiddleware();
    }

    /** @return array<string, array{InstitutionMode}> */
    public static function volets(): array
    {
        return ['école KLASSCI' => [InstitutionMode::Klassci], 'école autonome' => [InstitutionMode::Standalone]];
    }

    #[DataProvider('volets')]
    public function test_avant_la_publication_l_eleve_ne_voit_ni_note_ni_score_ni_commentaire(InstitutionMode $mode): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee($mode);
        $this->klassciProgramme(null);

        $copie = $this->maCopie($eleve, $evaluation);

        $copie->assertJsonPath('data.score', null)
            ->assertJsonPath('data.note_sur_20', null)
            ->assertJsonPath('data.feedback', null);
        self::assertStringNotContainsString('Bon travail', (string) $copie->getContent());
    }

    public function test_avant_la_publication_la_liste_de_l_eleve_tait_aussi_le_commentaire(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone);

        $liste = $this->asTenant($eleve)->getJson('/api/evaluations/student')->assertOk();

        $ligne = collect((array) $liste->json('data'))->firstWhere('id', $evaluation->getKey());
        self::assertIsArray($ligne);
        self::assertIsArray($ligne['student_submission'] ?? null);
        self::assertArrayNotHasKey('note_sur_20', $ligne['student_submission']);
        self::assertArrayNotHasKey('feedback', $ligne['student_submission']);
    }

    #[DataProvider('volets')]
    public function test_l_enseignant_publie_les_notes_apres_une_remise_et_l_eleve_les_voit(InstitutionMode $mode): void
    {
        [$enseignant, $eleve, $evaluation] = $this->evaluationCorrigee($mode);
        $this->klassciProgramme(null);
        // La seule voie d'avant, refusée dès la première copie : la note restait cachée.
        $this->asTenant($enseignant)->putJson('/api/evaluations/'.$evaluation->getKey(), ['show_results' => true])->assertStatus(409);

        $reponse = $this->asTenant($enseignant)->patchJson($this->url($evaluation), ['show_results' => true]);

        $reponse->assertOk()
            ->assertJsonPath('data.id', $evaluation->getKey())
            ->assertJsonPath('data.show_results', true)
            ->assertJsonPath('data.correction_release', 'with_results');
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'patch');
        $this->maCopie($eleve, $evaluation)
            ->assertJsonPath('data.note_sur_20', '14.00')
            ->assertJsonPath('data.feedback', 'Bon travail.');
    }

    public function test_l_enseignant_retire_une_publication(): void
    {
        [$enseignant, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone, ['show_results' => true]);

        $this->asTenant($enseignant)->patchJson($this->url($evaluation), ['show_results' => false])->assertOk();

        $this->maCopie($eleve, $evaluation)->assertJsonPath('data.note_sur_20', null)->assertJsonPath('data.feedback', null);
    }

    public function test_le_reglage_du_corrige_est_enregistre_et_sa_date_ne_survit_pas_a_un_autre_mode(): void
    {
        [$enseignant, , $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone);

        $aDate = $this->asTenant($enseignant)->patchJson($this->url($evaluation), [
            'correction_release' => 'at_date', 'correction_release_at' => '2026-12-01T08:00:00+00:00',
        ]);

        $aDate->assertOk()->assertJsonPath('data.correction_release', 'at_date')->assertJsonPath('data.show_results', false);
        $this->assertMatchesOpenApiResponse($aDate, self::CHEMIN, 'patch');
        self::assertTrue(CarbonImmutable::parse('2026-12-01 08:00:00', 'UTC')->equalTo((string) $aDate->json('data.correction_release_at')));

        $this->asTenant($enseignant)->patchJson($this->url($evaluation), ['correction_release' => 'after_close'])
            ->assertOk()
            ->assertJsonPath('data.correction_release', 'after_close')
            ->assertJsonPath('data.correction_release_at', null);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function saisiesRefusees(): array
    {
        return [
            'rien à régler' => [[], 'show_results'],
            'show_results non booléen' => [['show_results' => 'oui'], 'show_results'],
            'mode inconnu' => [['correction_release' => 'demain'], 'correction_release'],
            'at_date sans date' => [['correction_release' => 'at_date'], 'correction_release_at'],
            'une date pour un autre mode' => [['correction_release' => 'after_close', 'correction_release_at' => '2026-12-01T08:00:00+00:00'], 'correction_release_at'],
            'une date seule' => [['correction_release_at' => '2026-12-01T08:00:00+00:00'], 'correction_release_at'],
            'une date illisible' => [['correction_release' => 'at_date', 'correction_release_at' => 'lundi'], 'correction_release_at'],
        ];
    }

    /** @param  array<string, mixed>  $corps */
    #[DataProvider('saisiesRefusees')]
    public function test_422_une_saisie_refusee_ne_change_rien(array $corps, string $champ): void
    {
        [$enseignant, , $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone);

        $reponse = $this->asTenant($enseignant)->patchJson($this->url($evaluation), $corps);

        $reponse->assertUnprocessable()->assertJsonValidationErrors($champ);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'patch');
        $this->assertInchangee($evaluation);
    }

    public function test_403_un_autre_enseignant_de_l_ecole(): void
    {
        [, , $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone);
        $autre = $this->membre($this->ecoleDe($evaluation), 'enseignant');

        $this->refusee($this->asTenant($autre)->patchJson($this->url($evaluation), ['show_results' => true]), $evaluation);
    }

    public function test_403_un_coordinateur(): void
    {
        [, , $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone);
        $coordinateur = $this->membre($this->ecoleDe($evaluation), 'coordinateur');

        $this->refusee($this->asTenant($coordinateur)->patchJson($this->url($evaluation), ['show_results' => true]), $evaluation);
    }

    public function test_403_l_eleve_lui_meme(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone);

        $this->refusee($this->asTenant($eleve)->patchJson($this->url($evaluation), ['show_results' => true]), $evaluation);
    }

    public function test_403_l_admin_d_une_autre_ecole(): void
    {
        [, , $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone);
        $ailleurs = $this->membre($this->ecole(), 'admin');

        $this->refusee($this->asTenant($ailleurs)->patchJson($this->url($evaluation), ['show_results' => true]), $evaluation);
    }

    public function test_l_admin_de_l_ecole_publie(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone);
        $admin = $this->membre($this->ecoleDe($evaluation), 'admin');

        $this->asTenant($admin)->patchJson($this->url($evaluation), ['show_results' => true])->assertOk();

        $this->maCopie($eleve, $evaluation)->assertJsonPath('data.note_sur_20', '14.00');
    }

    public function test_401_sans_jeton(): void
    {
        [, , $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone);

        $reponse = $this->patchJson($this->url($evaluation), ['show_results' => true]);

        $reponse->assertUnauthorized();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'patch');
        $this->assertInchangee($evaluation);
    }

    // ───────────────────────── harnais

    private function refusee(TestResponse $reponse, Evaluation $evaluation): void
    {
        $reponse->assertForbidden();
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'patch');
        $this->assertInchangee($evaluation);
    }

    private function assertInchangee(Evaluation $evaluation): void
    {
        $relue = Evaluation::query()->withoutGlobalScopes()->findOrFail($evaluation->getKey());
        self::assertFalse($relue->show_results);
        self::assertNull($relue->correction_release_at);
    }

    private function ecoleDe(Evaluation $evaluation): Institution
    {
        return Institution::query()->findOrFail($evaluation->institution_id);
    }

    private function url(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->getKey().'/release';
    }
}
