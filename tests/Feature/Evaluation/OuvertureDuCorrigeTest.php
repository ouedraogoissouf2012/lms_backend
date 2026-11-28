<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
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
 * #1699 : le corrigé s'ouvre quand l'ENSEIGNANT le décide, jamais avant la fermeture de
 * l'évaluation pour tous. Lu sur « ma copie » (`GET /evaluations/{id}/my-submission`), le
 * seul chemin qui révèle le corrigé, sous vrai jeton.
 *
 * Avant : 7 jours fixes après la remise de CHAQUE élève (`CORRECTION_DELAY_DAYS`). Le premier
 * à remettre voyait le corrigé pendant que les autres composaient encore.
 *
 * Quatre modes (`correction_release`), une règle :
 * - `with_results` (défaut) : à la publication des notes ;
 * - `after_close` : à la fermeture, notes publiées ou non ;
 * - `at_date` : à la date choisie ;
 * - `never` : jamais.
 * Le PLANCHER vaut pour tous : jamais avant la fermeture pour tous. Elle se lit dans la source
 * de fenêtre de l'école : `end_at` programmé par KLASSCI, sinon la fin de la ligne
 * (`finPrevue()`). Une fenêtre illisible ou une évaluation sans fin gardent le corrigé fermé.
 */
final class OuvertureDuCorrigeTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use EvaluationCorrigee;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/evaluations/{id}/my-submission';

    /** La fin de l'évaluation du harnais : ouverte il y a trois jours, pour une heure. */
    private const FIN = '2026-11-24 11:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00:00', 'UTC'));
        $this->disableKlassciMiddleware();
    }

    public function test_par_defaut_le_corrige_attend_la_publication_des_notes(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone);

        $this->assertFerme($this->maCopie($eleve, $evaluation), null);

        $evaluation->update(['show_results' => true]);
        $ouvert = $this->maCopie($eleve, $evaluation);

        $this->assertOuvert($ouvert, self::FIN);
        $this->assertMatchesOpenApiResponse($ouvert, self::CHEMIN, 'get');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function modesQuiOuvrent(): array
    {
        return [
            'with_results, notes publiées' => [['correction_release' => 'with_results', 'show_results' => true]],
            'after_close' => [['correction_release' => 'after_close']],
            'at_date, date déjà passée' => [['correction_release' => 'at_date', 'correction_release_at' => '2026-11-20 08:00:00']],
        ];
    }

    /**
     * Le plancher : l'élève a remis tôt, ses camarades composent encore. Aucun mode n'ouvre le
     * corrigé avant la fin ; il s'ouvre à la fin.
     *
     * @param  array<string, mixed>  $reglage
     */
    #[DataProvider('modesQuiOuvrent')]
    public function test_plancher_jamais_avant_la_fermeture_pour_tous(array $reglage): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone, $reglage + [
            'date_evaluation' => now()->subMinutes(30), 'duree_minutes' => 60,
        ]);

        $this->assertFerme($this->maCopie($eleve, $evaluation), '2026-11-27 10:30:00');

        $this->travelTo(CarbonImmutable::parse('2026-11-27 10:30:00', 'UTC'));
        $this->assertOuvert($this->maCopie($eleve, $evaluation), '2026-11-27 10:30:00');
    }

    public function test_never_le_corrige_ne_s_ouvre_jamais(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone, [
            'correction_release' => 'never', 'show_results' => true,
        ]);

        $this->assertFerme($this->maCopie($eleve, $evaluation), null);
    }

    public function test_after_close_ouvre_le_corrige_sans_publier_les_notes(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone, ['correction_release' => 'after_close']);

        $copie = $this->maCopie($eleve, $evaluation);

        $this->assertOuvert($copie, self::FIN);
        $copie->assertJsonPath('data.note_sur_20', null)->assertJsonPath('data.feedback', null);
    }

    public function test_at_date_le_corrige_s_ouvre_a_la_date_choisie(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone, [
            'correction_release' => 'at_date', 'correction_release_at' => '2026-11-29 08:00:00',
        ]);

        $this->assertFerme($this->maCopie($eleve, $evaluation), '2026-11-29 08:00:00');

        $this->travelTo(CarbonImmutable::parse('2026-11-29 08:00:00', 'UTC'));
        $this->assertOuvert($this->maCopie($eleve, $evaluation), '2026-11-29 08:00:00');
    }

    public function test_sans_fin_connue_le_corrige_reste_ferme(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone, [
            'date_evaluation' => null, 'show_results' => true,
        ]);

        $this->assertFerme($this->maCopie($eleve, $evaluation), null);
    }

    public function test_le_delai_fixe_a_disparu(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Standalone, ['show_results' => true]);

        $this->maCopie($eleve, $evaluation)->assertJsonPath('data.correction_delay_days', null);
    }

    // ───────────────────────── école KLASSCI : la fin que KLASSCI programme

    public function test_klassci_la_fin_programmee_chez_klassci_ouvre_le_corrige(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Klassci, [
            'date_evaluation' => null, 'show_results' => true,
        ]);
        $this->klassciProgramme(['is_open' => false, 'has_started' => true, 'has_ended' => true, 'end_at' => '2026-11-26T18:00:00+00:00']);

        $ouvert = $this->maCopie($eleve, $evaluation);

        $this->assertOuvert($ouvert, '2026-11-26 18:00:00');
        $this->assertMatchesOpenApiResponse($ouvert, self::CHEMIN, 'get');
    }

    public function test_klassci_une_fin_encore_a_venir_chez_klassci_l_emporte_sur_la_ligne(): void
    {
        // La ligne locale dit « fermée depuis trois jours » ; KLASSCI a prolongé la fenêtre.
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Klassci, ['show_results' => true]);
        $this->klassciProgramme(['is_open' => true, 'has_started' => true, 'has_ended' => false, 'end_at' => '2026-11-28T18:00:00+00:00']);

        $this->assertFerme($this->maCopie($eleve, $evaluation), '2026-11-28 18:00:00');
    }

    public function test_klassci_sans_fenetre_chez_klassci_la_fin_de_la_ligne_vaut(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Klassci, ['show_results' => true]);
        $this->klassciProgramme(null);

        $this->assertOuvert($this->maCopie($eleve, $evaluation), self::FIN);
    }

    public function test_klassci_injoignable_le_corrige_reste_ferme(): void
    {
        [, $eleve, $evaluation] = $this->evaluationCorrigee(InstitutionMode::Klassci, ['show_results' => true]);
        $this->klassciProgramme(new \RuntimeException('KLASSCI injoignable'));

        $this->assertFerme($this->maCopie($eleve, $evaluation), null);
    }

    // ───────────────────────── harnais

    private function assertOuvert(TestResponse $copie, string $ouvreLe): void
    {
        $copie->assertJsonPath('data.correction_available', true)
            ->assertJsonPath('data.questions.0.correct_answers', ['a'])
            ->assertJsonPath('data.questions.0.explanation', 'Parce que.');
        $this->assertOuvreLe($copie, $ouvreLe);
    }

    private function assertFerme(TestResponse $copie, ?string $ouvreLe): void
    {
        $copie->assertJsonPath('data.correction_available', false)
            ->assertJsonMissingPath('data.questions.0.correct_answers')
            ->assertJsonMissingPath('data.questions.0.explanation');
        $this->assertOuvreLe($copie, $ouvreLe);
    }

    private function assertOuvreLe(TestResponse $copie, ?string $attendu): void
    {
        $lu = $copie->json('data.correction_available_at');
        if ($attendu === null) {
            self::assertNull($lu);

            return;
        }
        self::assertIsString($lu);
        self::assertTrue(CarbonImmutable::parse($attendu, 'UTC')->equalTo(CarbonImmutable::parse($lu)), "correction_available_at vaut {$lu}");
    }
}
