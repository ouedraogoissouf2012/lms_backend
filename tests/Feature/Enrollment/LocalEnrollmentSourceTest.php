<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;
use App\Services\Enrollment\InMemoryEnrollmentSource;
use App\Services\Enrollment\KlassciEnrollmentSource;
use App\Services\Enrollment\LocalEnrollmentSource;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #712 — un élève inscrit sur classe_etudiant voit ses cours sans user_classes.
 */
final class LocalEnrollmentSourceTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_student_enrolled_locally_sees_only_their_lessons(): void
    {
        $this->disableKlassciMiddleware();
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);

        $student = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
            'last_klassci_sync' => now(),
        ]);
        $classeA = Classe::factory()->create([
            'institution_id' => $institution->id,
            'klassci_id' => null,
        ]);
        $classeB = Classe::factory()->create([
            'institution_id' => $institution->id,
            'klassci_id' => null,
        ]);
        $student->classes()->attach($classeA->id, [
            'date_inscription' => now()->toDateString(),
            'statut' => 'actif',
            'institution_id' => $institution->id,
        ]);
        $mine = Lesson::factory()->create([
            'institution_id' => $institution->id,
            'classe_id' => $classeA->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        Lesson::factory()->create([
            'institution_id' => $institution->id,
            'classe_id' => $classeB->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $response = $this->asTenant($student)->getJson('/api/lessons/my-courses');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        self::assertTrue($ids->contains($mine->id));
        self::assertCount(1, $ids);
    }

    public function test_binding_resolves_klassci_then_local_as_different_classes(): void
    {
        $this->app->bind(EnrollmentSource::class, KlassciEnrollmentSource::class);
        $first = app(EnrollmentSource::class);
        $this->app->bind(EnrollmentSource::class, LocalEnrollmentSource::class);
        $second = app(EnrollmentSource::class);

        self::assertInstanceOf(KlassciEnrollmentSource::class, $first);
        self::assertInstanceOf(LocalEnrollmentSource::class, $second);
        self::assertNotSame($first::class, $second::class);
    }

    public function test_in_memory_fake_is_substitutable(): void
    {
        $fake = new InMemoryEnrollmentSource;
        $this->app->instance(EnrollmentSource::class, $fake);
        $user = new User;
        $user->id = 12;
        $fake->assign(12, 44);
        $fake->assign(12, 45);

        self::assertSame([44, 45], app(EnrollmentSource::class)->localClasseIdsFor($user));
    }
}
