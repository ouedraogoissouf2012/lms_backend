<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Chapter;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\QuizAnswer;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Chapter\LessonChapterProgressCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #963 — l'ordre du contenu d'une leçon est TOTAL, comme celui des leçons (#937).
 *
 * Des lignes égales sur l'ORDER BY reviennent dans un ordre libre (manuel
 * MySQL 8.0, §10.2.1.19) : les chapitres d'une leçon, les questions d'un quiz
 * ou leurs réponses pouvaient changer d'ordre d'un appel à l'autre. Seule une
 * colonne unique en dernier critère rend l'ordre déterministe.
 *
 * Les tests portent sur la REQUÊTE construite, indépendante du moteur : sous
 * SQLite, le `rowid` départage les égalités par hasard d'implémentation, et un
 * test sur les lignes rendues resterait vert sans le correctif.
 *
 * Les deux volets (école klassci, école autonome) sont sans objet : aucun de
 * ces tris ne lit le mode ni la source.
 *
 * @see app/Models/Lesson.php scopeOrdered(), le modèle (#937)
 */
final class ContenuOrdreTotalTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{0: class-string<Model>, 1: list<string>}> */
    public static function scopes(): iterable
    {
        yield 'chapitres' => [Chapter::class, ['chapters.order asc', 'chapters.created_at asc', 'chapters.id asc']];
        yield 'ressources' => [LessonResource::class, ['lesson_resources.order asc', 'lesson_resources.created_at asc', 'lesson_resources.id asc']];
        yield 'controles de connaissances' => [KnowledgeCheck::class, ['knowledge_checks.position asc', 'knowledge_checks.created_at asc', 'knowledge_checks.id asc']];
        yield 'questions de quiz' => [QuizQuestion::class, ['quiz_questions.order asc', 'quiz_questions.id asc']];
        yield 'reponses de quiz' => [QuizAnswer::class, ['quiz_answers.order asc', 'quiz_answers.id asc']];
    }

    /**
     * @param  class-string<Model>  $modele
     * @param  list<string>  $attendu
     */
    #[DataProvider('scopes')]
    public function test_le_scope_ordered_se_termine_par_l_identifiant(string $modele, array $attendu): void
    {
        /** @var Builder<Model> $requete */
        $requete = $modele::query()->ordered();

        self::assertSame($attendu, $this->criteres($requete));
    }

    public function test_les_chapitres_d_une_lecon_suivent_le_scope_ordered(): void
    {
        self::assertSame(
            ['chapters.order asc', 'chapters.created_at asc', 'chapters.id asc'],
            $this->criteres((new Lesson)->chapters()->getQuery()),
        );
    }

    public function test_la_progression_par_chapitres_lit_les_chapitres_dans_l_ordre_total(): void
    {
        $lecon = Lesson::factory()->create();
        Chapter::factory()->create(['lesson_id' => $lecon->id]);
        $apprenant = User::factory()->student()->create();

        $requetes = [];
        DB::listen(static function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = str_replace(['"', '`'], '', $requete->sql);
        });

        (new LessonChapterProgressCalculator)->calculate($apprenant->id, $lecon->id);

        $lectureDesChapitres = array_values(array_filter(
            $requetes,
            static fn (string $sql): bool => str_starts_with($sql, 'select * from chapters'),
        ));
        self::assertCount(1, $lectureDesChapitres);
        self::assertStringEndsWith(
            'order by chapters.order asc, chapters.created_at asc, chapters.id asc',
            $lectureDesChapitres[0],
        );
    }

    /**
     * @param  Builder<Model>  $requete
     * @return list<string>
     */
    private function criteres(Builder $requete): array
    {
        return array_map(
            static fn (array $critere): string => $critere['column'].' '.$critere['direction'],
            $requete->getQuery()->orders ?? [],
        );
    }
}
