<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function student_can_access_student_dashboard()
    {
        $student = User::factory()->create(['role' => 'etudiant']);
        $token = $student->createToken('test')->plainTextToken;

        // Créer des données de test
        $lesson = Lesson::factory()->create();
        LessonProgress::factory()->create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
            'progress_percentage' => 50,
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/dashboard/student');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'ongoing_lessons',
                    'completed_lessons',
                    'upcoming_quizzes',
                    'progression' => [
                        'lessons' => [
                            'total',
                            'completed',
                            'in_progress',
                            'average_progress',
                            'total_time_spent_minutes',
                        ],
                        'quizzes',
                    ],
                    'notifications',
                    'forum_activity',
                ],
            ]);
    }

    /** @test */
    public function teacher_can_access_teacher_dashboard()
    {
        $teacher = User::factory()->create(['role' => 'enseignant']);
        $token = $teacher->createToken('test')->plainTextToken;

        // Créer des cours pour l'enseignant
        Lesson::factory()->count(3)->create([
            'enseignant_id' => $teacher->id,
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/dashboard/teacher');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'lessons' => [
                        'total',
                        'published',
                        'draft',
                        'top_lessons',
                    ],
                    'students',
                    'quizzes',
                    'forum',
                ],
            ]);

        $this->assertEquals(3, $response->json('data.lessons.total'));
    }

    /** @test */
    public function student_cannot_access_teacher_dashboard()
    {
        $student = User::factory()->create(['role' => 'etudiant']);
        $token = $student->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/dashboard/teacher');

        $response->assertStatus(403); // Forbidden
    }

    /** @test */
    public function coordinator_can_access_stats_dashboard()
    {
        $coordinator = User::factory()->create(['role' => 'coordinateur']);
        $token = $coordinator->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/dashboard/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'users',
                    'lessons',
                    'quizzes',
                    'forum',
                    'notifications',
                    'recent_activity',
                ],
            ]);
    }

    /** @test */
    public function student_cannot_access_stats_dashboard()
    {
        $student = User::factory()->create(['role' => 'etudiant']);
        $token = $student->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/dashboard/stats');

        $response->assertStatus(403);
    }

    /** @test */
    public function dashboard_requires_authentication()
    {
        $response = $this->getJson('/api/dashboard/student');
        $response->assertStatus(401);
    }
}
