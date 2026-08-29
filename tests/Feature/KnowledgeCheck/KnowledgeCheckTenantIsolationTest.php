<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeCheck;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #524 — isolation multi-tenant des KnowledgeCheck (§1.3).
 *
 * Un acteur de l'institution A ne doit jamais lire / tenter / modifier / supprimer
 * un KnowledgeCheck de l'institution B. L'isolation repose sur le global scope
 * `BelongsToInstitution` + `ResolveInstitution`, qui résout le tenant
 * EXCLUSIVEMENT depuis le Bearer token (jamais via un en-tête client). Ces tests
 * emploient donc un VRAI Bearer token (comme en production) — `Sanctum::actingAs`
 * ne déclencherait pas la résolution du tenant et masquerait l'isolation.
 *
 * @see app/Models/Traits/BelongsToInstitution.php
 * @see app/Http/Middleware/ResolveInstitution.php
 */
final class KnowledgeCheckTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // ResolveInstitution (global) reste actif ; on neutralise seulement la
        // re-synchro KLASSCI pour ne pas interférer avec le test d'isolation.
        $this->disableKlassciMiddleware();
    }

    /**
     * @return array{teacher: User, student: User, quiz: KnowledgeCheck}
     */
    private function buildInstitution(string $slug): array
    {
        $institution = Institution::factory()->create(['slug' => $slug]);
        $teacher = User::factory()->create(['institution_id' => $institution->id, 'role' => 'enseignant']);
        $student = User::factory()->create(['institution_id' => $institution->id, 'role' => 'etudiant']);

        $lesson = Lesson::factory()->create([
            'institution_id' => $institution->id,
            'enseignant_id' => $teacher->id,
            'status' => 'published',
        ]);
        $chapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $institution->id,
            'enseignant_id' => $teacher->id,
            'content_type' => 'quiz',
            'order' => 0,
        ]);
        $quiz = KnowledgeCheck::factory()->create([
            'chapter_id' => $chapter->id,
            'institution_id' => $institution->id,
            'is_active' => true,
        ]);

        return ['teacher' => $teacher, 'student' => $student, 'quiz' => $quiz];
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('iso-524')->plainTextToken];
    }

    public function test_teacher_cannot_read_knowledge_check_of_another_institution(): void
    {
        $a = $this->buildInstitution('kc-iso-a');
        $b = $this->buildInstitution('kc-iso-b');

        $this->withHeaders($this->bearer($a['teacher']))
            ->getJson("/api/knowledge-checks/{$b['quiz']->id}")
            ->assertStatus(404);
    }

    public function test_teacher_cannot_update_knowledge_check_of_another_institution(): void
    {
        $a = $this->buildInstitution('kc-iso-a2');
        $b = $this->buildInstitution('kc-iso-b2');

        $this->withHeaders($this->bearer($a['teacher']))
            ->putJson("/api/knowledge-checks/{$b['quiz']->id}", ['title' => 'hijack'])
            ->assertStatus(404);
    }

    public function test_teacher_cannot_delete_knowledge_check_of_another_institution(): void
    {
        $a = $this->buildInstitution('kc-iso-a3');
        $b = $this->buildInstitution('kc-iso-b3');

        $this->withHeaders($this->bearer($a['teacher']))
            ->deleteJson("/api/knowledge-checks/{$b['quiz']->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('knowledge_checks', ['id' => $b['quiz']->id]);
    }

    public function test_student_cannot_start_attempt_on_knowledge_check_of_another_institution(): void
    {
        $a = $this->buildInstitution('kc-iso-a4');
        $b = $this->buildInstitution('kc-iso-b4');

        $this->withHeaders($this->bearer($a['student']))
            ->postJson("/api/knowledge-checks/{$b['quiz']->id}/start")
            ->assertStatus(404);
    }

    public function test_same_tenant_teacher_can_read_own_knowledge_check(): void
    {
        $a = $this->buildInstitution('kc-iso-same');

        $this->withHeaders($this->bearer($a['teacher']))
            ->getJson("/api/knowledge-checks/{$a['quiz']->id}")
            ->assertStatus(200);
    }
}
