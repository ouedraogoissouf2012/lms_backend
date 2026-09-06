<?php

declare(strict_types=1);

namespace Tests\Feature\Audience;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Audience\ClasseAudienceSource;
use App\Services\Audience\InMemoryClasseAudienceSource;
use App\Services\Audience\LocalClasseAudienceSource;
use App\Services\Seances\Mutations\ParticipantValidationService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #712 — l'entrée visio ne fait plus d'HTTP KLASSCI.
 * Ce test échouait tant que ParticipantValidationService appelait /classes/{id}/etudiants.
 */
final class ParticipantValidationLocalAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);
        Http::fake();
    }

    public function test_enrolled_student_is_authorized_without_http(): void
    {
        [$seance, $student] = $this->seanceAndStudent(enrolled: true);

        $result = app(ParticipantValidationService::class)
            ->validate($seance->id, $student->id, $student);

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['authorized']);
        Http::assertNothingSent();
    }

    public function test_unenrolled_student_is_denied_without_http(): void
    {
        [$seance, $student] = $this->seanceAndStudent(enrolled: false);

        $result = app(ParticipantValidationService::class)
            ->validate($seance->id, $student->id, $student);

        $this->assertSame(403, $result['status']);
        $this->assertSame('not_enrolled', $result['payload']['reason']);
        Http::assertNothingSent();
    }

    public function test_container_binds_local_audience_not_a_singleton(): void
    {
        $first = app(ClasseAudienceSource::class);
        $second = app(ClasseAudienceSource::class);

        $this->assertInstanceOf(LocalClasseAudienceSource::class, $first);
        $this->assertNotSame($first, $second);
    }

    public function test_in_memory_fake_is_substitutable(): void
    {
        [$seance, $student] = $this->seanceAndStudent(enrolled: false);
        $fake = new InMemoryClasseAudienceSource;
        $fake->enroll($seance->id, $student->id);
        $this->app->instance(ClasseAudienceSource::class, $fake);

        $result = app(ParticipantValidationService::class)
            ->validate($seance->id, $student->id, $student);

        $this->assertTrue($result['payload']['authorized']);
        Http::assertNothingSent();
    }

    /**
     * @return array{0: Seance, 1: User}
     */
    private function seanceAndStudent(bool $enrolled): array
    {
        $student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
        ]);
        $classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
        ]);
        $seance = Seance::factory()->visioActive()->create([
            'institution_id' => $this->institution->id,
            'klassci_classe_id' => $classe->klassci_id,
        ]);

        if ($enrolled) {
            $classe->etudiants()->attach($student->id, ['statut' => 'actif']);
        }

        return [$seance, $student];
    }
}
