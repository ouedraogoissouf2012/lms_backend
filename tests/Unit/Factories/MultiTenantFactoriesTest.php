<?php

declare(strict_types=1);

namespace Tests\Unit\Factories;

use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\File;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Matiere;
use App\Models\Notification;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Seance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test : chaque factory de modèle tenant-scoped doit poser `institution_id`.
 *
 * Pourquoi : on a trouvé 3 fois cette session des tests qui échouaient
 * parce que la factory n'injectait pas `institution_id`, causant le
 * `BelongsToInstitution` global scope à filtrer les rows une fois le tenant
 * résolu. Cette suite empêche que le bug réapparaisse silencieusement.
 *
 * Test PR #143/#144/#146 ont fait des fixes one-off au cas par cas.
 * Cette PR (test-03) corrige les factories et cette suite verrouille la garantie.
 */
final class MultiTenantFactoriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque entrée = un modèle qui `use BelongsToInstitution` et a une factory.
     *
     * @return iterable<string, array{0: class-string}>
     */
    public static function tenantScopedFactoriesProvider(): iterable
    {
        yield 'Chapter'             => [Chapter::class];
        yield 'Classe'              => [Classe::class];
        yield 'Evaluation'          => [Evaluation::class];
        yield 'EvaluationQuestion'  => [EvaluationQuestion::class];
        yield 'EvaluationSubmission' => [EvaluationSubmission::class];
        yield 'File'                => [File::class];
        yield 'ForumCategory'       => [ForumCategory::class];
        yield 'ForumPost'           => [ForumPost::class];
        yield 'ForumTopic'          => [ForumTopic::class];
        yield 'KnowledgeCheck'      => [KnowledgeCheck::class];
        yield 'Lesson'              => [Lesson::class];
        yield 'LessonProgress'      => [LessonProgress::class];
        yield 'Matiere'             => [Matiere::class];
        yield 'Notification'        => [Notification::class];
        yield 'Quiz'                => [Quiz::class];
        yield 'QuizAttempt'         => [QuizAttempt::class];
        yield 'QuizQuestion'        => [QuizQuestion::class];
        yield 'Seance'              => [Seance::class];
    }

    /**
     * @dataProvider tenantScopedFactoriesProvider
     */
    public function test_factory_assigns_institution_id(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        self::assertNotNull(
            $model->institution_id,
            sprintf(
                '%s::factory() doit poser `institution_id`. Sinon le global scope '
                . 'BelongsToInstitution filtrera ce row une fois le tenant résolu.',
                $modelClass,
            ),
        );

        // L'institution doit exister en DB (pas un id orphelin).
        self::assertDatabaseHas('institutions', ['id' => $model->institution_id]);
    }
}
