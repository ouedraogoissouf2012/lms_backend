<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #481 — invariant `status=published ⇒ published_at != null`.
 *
 * Des leçons créées en contournant LessonCrudOperationsService (factory,
 * seeder, tinker, import) avaient `status='published'` mais `published_at=NULL`
 * → invisibles via `Lesson::published()` (qui exige `whereNotNull('published_at')`).
 *
 * Ces tests figent l'invariant garanti par LessonObserver, quel que soit le
 * point d'écriture, dans les deux sens (published ⇒ date, draft/archived ⇒ null),
 * sans écraser une date de publication déjà posée.
 *
 * @see app/Observers/LessonObserver.php
 * @see app/Models/Lesson.php scopePublished
 * @see .claude/specs/lesson-published-at-invariant/
 */
final class LessonPublishedAtInvariantTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createLesson(array $attributes): Lesson
    {
        return Lesson::create($attributes + [
            'title' => 'Titre',
            'enseignant_id' => $this->teacher->id,
            'institution_id' => $this->institution->id,
        ]);
    }

    public function test_published_lesson_without_date_gets_published_at(): void
    {
        // Contournement direct du service (REQ-1/REQ-4).
        $lesson = $this->createLesson(['status' => 'published']);

        $this->assertNotNull($lesson->published_at, 'published_at doit être posé automatiquement.');
        $this->assertTrue(
            Lesson::published()->whereKey($lesson->id)->exists(),
            'Une leçon publiée doit être visible via le scope published().'
        );
    }

    public function test_factory_published_state_is_visible(): void
    {
        $lesson = Lesson::factory()->published()->create([
            'institution_id' => $this->institution->id,
            'enseignant_id' => $this->teacher->id,
        ]);

        $this->assertNotNull($lesson->published_at);
        $this->assertTrue(Lesson::published()->whereKey($lesson->id)->exists());
    }

    public function test_draft_lesson_has_null_published_at(): void
    {
        // Même si on tente de forcer une date, un draft ne doit pas la garder (REQ-2).
        $lesson = $this->createLesson([
            'status' => 'draft',
            'published_at' => now(),
        ]);

        $this->assertNull($lesson->published_at);
    }

    public function test_archived_lesson_has_null_published_at(): void
    {
        $lesson = $this->createLesson([
            'status' => 'archived',
            'published_at' => now(),
        ]);

        $this->assertNull($lesson->published_at);
    }

    public function test_existing_published_at_is_not_overwritten(): void
    {
        // Une date de publication passée explicite doit être PRÉSERVÉE (REQ-3).
        $explicit = now()->subYears(3)->startOfDay();
        $lesson = $this->createLesson([
            'status' => 'published',
            'published_at' => $explicit,
        ]);

        $this->assertTrue(
            $lesson->published_at->equalTo($explicit),
            'La date de publication explicite ne doit pas être réécrite à now().'
        );
    }
}
