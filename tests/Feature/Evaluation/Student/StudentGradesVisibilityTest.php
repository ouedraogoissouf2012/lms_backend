<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Quelles notes l'élève voit dans « Mes notes » — et lesquelles il ne doit pas voir.
 *
 * ## Le défaut
 *
 * `fetchCorrectedSubmissions()` filtrait `status = 'corrige'`. Or `corrige`
 * n'est posé QUE par `EvaluationGradingService::manualGrade()`, c'est-à-dire la
 * correction manuelle d'une dissertation. Une évaluation entièrement
 * auto-corrigée reste `soumis` à jamais : sa note existe, elle est juste, et
 * l'écran censé la montrer à l'élève la filtrait.
 *
 * `GUIDE_UTILISATEUR_ETUDIANT.md:201-214` promet pourtant « Votre note
 * (ex : 15/20) » sans aucune condition de correction manuelle.
 *
 * ## Le critère retenu : la note est-elle FINALE ?
 *
 * Et non « a-t-elle été corrigée à la main ». Une note est finale si plus rien
 * ne peut la changer :
 *   - `corrige` → l'enseignant a tranché ;
 *   - `soumis` SANS question à correction manuelle → l'auto-correction est le
 *     dernier mot ;
 *   - `soumis` AVEC une dissertation non notée → la note est DÉFLATÉE (la
 *     dissertation compte 0 au dénominateur), elle n'est pas finale, et la
 *     montrer serait annoncer un échec qui n'a pas eu lieu.
 *
 * C'est exactement le critère que la synchro KLASSCI applique déjà pour refuser
 * de pousser (409). L'élève voit donc ce qui est — ou sera — transmis comme
 * officiel, jamais autre chose.
 */
final class StudentGradesVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const KLASSCI_STUDENT_ID = 7777;

    private Institution $institution;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getMatieres')->andReturn(['success' => true, 'data' => []]);
        });

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => self::KLASSCI_STUDENT_ID,
            'klassci_token' => 'jeton',
        ]);
    }

    private function evaluation(string $titre, bool $avecDissertation): Evaluation
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
            'titre' => $titre,
            'is_published' => true,
            'coefficient' => 1,
        ]);

        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'type' => $avecDissertation ? 'dissertation' : 'qcm',
            'points' => 10,
        ]);

        return $evaluation;
    }

    private function soumission(Evaluation $evaluation, string $statut, ?string $feedback = null): void
    {
        EvaluationSubmission::create([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => self::KLASSCI_STUDENT_ID,
            'institution_id' => $this->institution->id,
            'attempt' => 1,
            'status' => $statut,
            'note_sur_20' => 14.0,
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
            'feedback' => $feedback,
        ]);
    }

    /** @return list<string> Les titres réellement rendus à l'élève. */
    private function titresVus(): array
    {
        Sanctum::actingAs($this->student);

        $matieres = $this->getJson('/api/my-grades')->assertStatus(200)->json('data.matieres');

        $titres = [];
        foreach ($matieres as $matiere) {
            foreach ($matiere['evaluations'] as $eval) {
                $titres[] = $eval['titre'];
            }
        }
        sort($titres);

        return $titres;
    }

    public function test_une_evaluation_auto_corrigee_est_visible_par_l_eleve(): void
    {
        $this->soumission($this->evaluation('QCM auto-corrige', avecDissertation: false), 'soumis');

        self::assertSame(['QCM auto-corrige'], $this->titresVus());
    }

    public function test_une_dissertation_non_corrigee_reste_invisible_car_sa_note_est_deflatee(): void
    {
        $this->soumission($this->evaluation('Dissertation en attente', avecDissertation: true), 'soumis');

        self::assertSame([], $this->titresVus());
    }

    public function test_une_dissertation_corrigee_est_visible(): void
    {
        $this->soumission($this->evaluation('Dissertation notee', avecDissertation: true), 'corrige');

        self::assertSame(['Dissertation notee'], $this->titresVus());
    }

    public function test_un_essai_d_entrainement_reste_invisible(): void
    {
        $this->soumission(
            $this->evaluation('Entrainement', avecDissertation: false),
            'soumis',
            '[PRACTICE] Entraînement - note non officielle',
        );

        self::assertSame([], $this->titresVus());
    }
}
