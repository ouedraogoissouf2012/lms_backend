<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Enums\LessonStatus;
use App\Models\Chapter;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #621 — un étudiant d'une autre classe du même tenant ne lit pas le chapitre.
 */
final class ChapterEnrollmentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrolled_student_reads_the_chapter(): void
    {
        [$chapter, $enrolled] = $this->chapterAndStudents();

        $this->actingWithToken($enrolled)
            ->getJson("/api/chapters/{$chapter->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $chapter->id);
    }

    public function test_other_class_student_cannot_read_the_chapter(): void
    {
        [$chapter, , $outsider] = $this->chapterAndStudents();

        $this->actingWithToken($outsider)
            ->getJson("/api/chapters/{$chapter->id}")
            ->assertStatus(404);
    }

    public function test_other_class_student_cannot_list_the_lesson_chapters(): void
    {
        [$chapter, , $outsider] = $this->chapterAndStudents();

        $this->actingWithToken($outsider)
            ->getJson("/api/lessons/{$chapter->lesson_id}/chapters")
            ->assertStatus(404);
    }

    public function test_other_class_student_cannot_download_the_source(): void
    {
        [$chapter, , $outsider] = $this->chapterAndStudents();

        $this->actingWithToken($outsider)
            ->getJson("/api/chapters/{$chapter->id}/original")
            ->assertStatus(404);
    }

    public function test_teacher_still_sees_every_tenant_chapter(): void
    {
        [$chapter] = $this->chapterAndStudents();
        $teacher = $this->user($chapter->institution, 'enseignant');

        $this->actingWithToken($teacher)
            ->getJson("/api/chapters/{$chapter->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $chapter->id);
    }

    private function actingWithToken(User $user): static
    {
        return $this->withToken($user->createToken('test-621')->plainTextToken);
    }

    /**
     * @return array{0: Chapter, 1: User, 2: User}
     */
    private function chapterAndStudents(): array
    {
        $institution = Institution::factory()->create(['is_active' => true]);
        $teacher = $this->user($institution, 'enseignant');
        $enrolled = $this->user($institution, 'etudiant');
        $outsider = $this->user($institution, 'etudiant');

        $classeA = $this->enroll($enrolled, $institution, 6211);
        $this->enroll($outsider, $institution, 6212);

        $lesson = Lesson::factory()->create([
            'institution_id' => $institution->id,
            'classe_id' => $classeA->id,
            'enseignant_id' => $teacher->id,
            'status' => LessonStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $chapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $institution->id,
            'enseignant_id' => $teacher->id,
            'allow_download' => true,
        ]);

        return [$chapter, $enrolled, $outsider];
    }

    private function enroll(User $student, Institution $institution, int $klassciId): Classe
    {
        $classe = Classe::factory()->create([
            'institution_id' => $institution->id,
            'klassci_id' => $klassciId,
        ]);

        UserClass::create([
            'user_id' => $student->id,
            'klassci_classe_id' => $klassciId,
            'classe_nom' => $classe->libelle,
            'institution_id' => $institution->id,
            'synced_at' => now(),
        ]);

        return $classe;
    }

    private function user(Institution $institution, string $role): User
    {
        return User::factory()->for($institution)->create([
            'role' => $role,
            'last_klassci_sync' => now(),
        ]);
    }
}
