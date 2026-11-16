<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1147. La mesure (PR #1150, run 43823360402) a montré, dans les deux types d'école :
 * après 10 min de temps écrites sur un chapitre, la ligne `lesson_progress` restait
 * `not_started` à 0 %, comptée dans le total et le temps, mais ni dans « en cours » ni
 * dans « élèves ayant commencé ». Le serveur disait « 10 min passées » ET « pas
 * commencée ».
 *
 * L'état attendu : une leçon dont du temps est enregistré est COMMENCÉE (`in_progress`,
 * `started_at` posé). Le pourcentage, lui, reste la part des chapitres terminés (0 %).
 * Une leçon terminée ne rétrograde jamais.
 *
 * Vrai jeton.
 */
final class TempsEcritCommenceLaLeconTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('volets')]
    public function test_du_temps_ecrit_commence_la_lecon_et_les_compteurs_le_disent(InstitutionMode $mode): void
    {
        [$ecole, $eleve, $lecon, $chapitre] = $this->leconEtSonEleve($mode);

        $this->asTenant($eleve)->postJson("/api/chapters/{$chapitre->id}/time", ['time_spent_seconds' => 600])->assertOk();

        $ligne = $this->ligne($eleve, $lecon);
        self::assertSame('in_progress', $ligne->status, 'du temps passé : la leçon est commencée');
        self::assertNotNull($ligne->started_at, 'le début est daté');
        self::assertSame(0, (int) $ligne->progress_percentage, 'le pourcentage reste celui des chapitres terminés');
        self::assertSame(10, (int) $ligne->time_spent_minutes);

        $this->asTenant($eleve)->getJson('/api/dashboard/student')->assertOk()
            ->assertJsonPath('data.progression.lessons.total', 1)
            ->assertJsonPath('data.progression.lessons.in_progress', 1)
            ->assertJsonPath('data.progression.lessons.completed', 0);
        $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson("/api/lessons/{$lecon->id}/progress")->assertOk()
            ->assertJsonPath('statistics.total_students', 1)
            ->assertJsonPath('statistics.students_started', 1);
    }

    /** Témoin : une leçon TERMINÉE ne rétrograde pas quand du temps s'écrit encore. */
    #[DataProvider('volets')]
    public function test_une_lecon_terminee_reste_terminee(InstitutionMode $mode): void
    {
        [, $eleve, $lecon, $chapitre] = $this->leconEtSonEleve($mode);
        $termine = now()->subDay()->startOfSecond();
        LessonProgress::query()->withoutGlobalScopes()->create([
            'user_id' => $eleve->id, 'lesson_id' => $lecon->id, 'institution_id' => $lecon->institution_id,
            'status' => 'completed', 'progress_percentage' => 100, 'started_at' => $termine, 'completed_at' => $termine,
        ]);

        $this->asTenant($eleve)->postJson("/api/chapters/{$chapitre->id}/time", ['time_spent_seconds' => 600])->assertOk();

        $ligne = $this->ligne($eleve, $lecon);
        self::assertSame('completed', $ligne->status);
        self::assertSame($termine->toDateTimeString(), $ligne->started_at?->toDateTimeString(), 'le début n est pas réécrit');
    }

    /** @return array{0: Institution, 1: User, 2: Lesson, 3: Chapter} */
    private function leconEtSonEleve(InstitutionMode $mode): array
    {
        $ecole = $this->ecole($mode);
        $classe = $this->classe($ecole, '6e A');
        $auteur = $this->membre($ecole, 'enseignant');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $classe, 'actif');
        $lecon = Lesson::factory()->published()->create([
            'institution_id' => $ecole->id, 'classe_id' => $classe->id, 'enseignant_id' => $auteur->id,
        ]);
        $chapitre = Chapter::factory()->create([
            'lesson_id' => $lecon->id, 'institution_id' => $ecole->id, 'enseignant_id' => $auteur->id,
            'content_type' => 'text', 'order' => 0,
        ]);

        return [$ecole, $eleve, $lecon, $chapitre];
    }

    private function ligne(User $eleve, Lesson $lecon): LessonProgress
    {
        return LessonProgress::query()->withoutGlobalScopes()->where(['user_id' => $eleve->id, 'lesson_id' => $lecon->id])->firstOrFail();
    }
}
