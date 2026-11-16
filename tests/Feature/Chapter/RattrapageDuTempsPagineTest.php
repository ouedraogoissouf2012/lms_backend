<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Enums\InstitutionMode;
use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Services\Chapter\LessonTimeRecomputation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1148 : le rattrapage du temps (`lessons:recompute-time-spent`) parcourt les
 * couples (utilisateur, leçon) par pages bornées, à la CLÉ ; l'erreur d'un couple
 * n'arrête pas les suivants ; la simulation compte les mêmes refus que l'application.
 */
final class RattrapageDuTempsPagineTest extends TestCase
{
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école du LMS' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    /**
     * Cinq agrégats orphelins (du temps stocké, aucun temps de chapitre), pages de
     * deux. Chaque couple corrigé à 0 SORT de l'ensemble parcouru : une pagination
     * par décalage sauterait les couples 3 et 4. La clé n'en saute aucun.
     */
    #[DataProvider('volets')]
    public function test_un_couple_corrige_qui_sort_de_l_ensemble_ne_fait_sauter_aucun_suivant(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $eleve = $this->membre($ecole, 'etudiant');
        $lecons = collect(range(1, 5))->map(fn (): Lesson => $this->lecon($ecole));
        $lecons->each(fn (Lesson $lecon) => $this->agregatStocke($eleve, $lecon, $ecole, 5));

        self::assertSame(
            ['couples' => 5, 'a_corriger' => 5, 'refus' => 0, 'corriges' => 0, 'erreurs' => 0],
            $this->rattrapage(false, 2),
            'la simulation voit les cinq couples, sur trois pages',
        );
        self::assertSame(
            ['couples' => 5, 'a_corriger' => 5, 'refus' => 0, 'corriges' => 5, 'erreurs' => 0],
            $this->rattrapage(true, 2),
        );
        self::assertSame(0, (int) LessonProgress::query()->withoutGlobalScopes()->sum('time_spent_minutes'), 'aucun couple sauté');
        self::assertSame(0, $this->rattrapage(false, 2)['a_corriger'], 'une seconde passe ne trouve plus rien');
    }

    /** Le premier couple lève : le second, et l'établissement suivant, sont traités. */
    #[DataProvider('volets')]
    public function test_l_erreur_d_un_couple_n_arrete_ni_les_suivants_ni_l_ecole_suivante(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $autre = $this->ecole($mode === InstitutionMode::Klassci ? InstitutionMode::Standalone : InstitutionMode::Klassci);
        [$fautif, $suivant, $ailleurs] = [$this->membre($ecole, 'etudiant'), $this->membre($ecole, 'etudiant'), $this->membre($autre, 'etudiant')];
        foreach ([[$fautif, $ecole], [$suivant, $ecole], [$ailleurs, $autre]] as [$eleve, $son]) {
            $this->agregatStocke($eleve, $this->lecon($son), $son, 7);
        }
        LessonProgress::saving(function (LessonProgress $ligne) use ($fautif): void {
            if ((int) $ligne->user_id === $fautif->id) {
                throw new RuntimeException('écriture refusée par le test');
            }
        });
        Log::spy();

        self::assertSame(
            ['couples' => 3, 'a_corriger' => 3, 'refus' => 0, 'corriges' => 2, 'erreurs' => 1],
            $this->rattrapage(true, 500),
        );
        self::assertSame(0, $this->agregatDe($suivant), 'le couple suivant est corrigé');
        self::assertSame(0, $this->agregatDe($ailleurs), 'l établissement suivant est traité');
        self::assertSame(7, $this->agregatDe($fautif), 'le couple en erreur est intact');
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $contexte): bool => ($contexte['user_id'] ?? null) === $fautif->id
                && ($contexte['institution_id'] ?? null) === $ecole->id,
        );
    }

    /**
     * Une ligne d'un AUTRE établissement, une leçon supprimée : l'écriture les
     * refuserait. La simulation les compte en `refus` À L'IDENTIQUE, et
     * l'application n'écrit rien pour eux, sans erreur.
     */
    #[DataProvider('volets')]
    public function test_la_simulation_compte_les_memes_refus_que_l_application(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $autre = $this->ecole($mode === InstitutionMode::Klassci ? InstitutionMode::Standalone : InstitutionMode::Klassci);
        $eleve = $this->membre($ecole, 'etudiant');
        $horsTenant = $this->lecon($ecole);
        $this->tempsDeChapitre($eleve, $horsTenant, 600);
        DB::table('lesson_progress')->insert([
            'user_id' => $eleve->id, 'lesson_id' => $horsTenant->id, 'institution_id' => $autre->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $supprimee = $this->lecon($ecole);
        $this->tempsDeChapitre($eleve, $supprimee, 600);
        $supprimee->delete();

        $attendu = ['couples' => 2, 'a_corriger' => 2, 'refus' => 2, 'corriges' => 0, 'erreurs' => 0];
        self::assertSame($attendu, $this->rattrapage(false, 500), 'simulation');
        self::assertSame($attendu, $this->rattrapage(true, 500), 'application');
        self::assertSame(0, $this->agregatDe($eleve, $horsTenant), 'la ligne de l autre établissement est intacte');
        self::assertFalse(
            LessonProgress::query()->withoutGlobalScopes()->where('lesson_id', $supprimee->id)->exists(),
            'aucune ligne créée pour une leçon supprimée',
        );
    }

    public function test_la_commande_affiche_refus_et_erreurs(): void
    {
        $this->artisan('lessons:recompute-time-spent')
            ->expectsOutputToContain('refusés : 0')
            ->expectsOutputToContain('erreurs : 0')
            ->assertSuccessful();
    }

    /** @return array<string, int> */
    private function rattrapage(bool $apply, int $taille): array
    {
        return app(LessonTimeRecomputation::class)->run($apply, null, $taille);
    }

    private function lecon(Institution $ecole): Lesson
    {
        return Lesson::factory()->published()->create([
            'institution_id' => $ecole->id,
            'classe_id' => $this->classe($ecole, 'Classe '.uniqid())->id,
            'enseignant_id' => $this->membre($ecole, 'enseignant')->id,
        ]);
    }

    /** Agrégat stocké sans temps de chapitre (l'état d'avant #944). */
    private function agregatStocke(User $eleve, Lesson $lecon, Institution $ecole, int $minutes): void
    {
        DB::table('lesson_progress')->insert([
            'user_id' => $eleve->id, 'lesson_id' => $lecon->id, 'institution_id' => $ecole->id,
            'time_spent_minutes' => $minutes, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tempsDeChapitre(User $eleve, Lesson $lecon, int $secondes): void
    {
        $chapitre = Chapter::factory()->create([
            'lesson_id' => $lecon->id, 'institution_id' => $lecon->institution_id,
            'enseignant_id' => $lecon->enseignant_id, 'content_type' => 'text', 'order' => 0,
        ]);
        ChapterProgress::query()->withoutGlobalScopes()->create([
            'user_id' => $eleve->id, 'chapter_id' => $chapitre->id,
            'institution_id' => $lecon->institution_id, 'time_spent_seconds' => $secondes,
        ]);
    }

    private function agregatDe(User $eleve, ?Lesson $lecon = null): int
    {
        return (int) LessonProgress::query()->withoutGlobalScopes()->where('user_id', $eleve->id)
            ->when($lecon !== null, fn ($q) => $q->where('lesson_id', $lecon?->id))->value('time_spent_minutes');
    }
}
