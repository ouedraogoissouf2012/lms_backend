<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;
use App\Services\Enrollment\InMemoryEnrollmentSource;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * #712 — liste des classes enseignant depuis le miroir local, sans HTTP.
 *
 * ## Ce qui a changé, et pourquoi
 *
 * Ces tests assertaient l'identifiant LOCAL. Le frontend, lui, fusionne cette
 * réponse avec le référentiel `/proxy/classes` — du KLASSCI — **par `id`** :
 *
 *     parId.set(String(classe.id), classe)            // référentiel KLASSCI
 *     const reference = parId.get(String(classe?.id)) // notre réponse
 *
 * Émettre du local ne pouvait donc jamais apparier, et les effectifs seraient
 * restés à « — ». L'endpoint rend désormais l'espace KLASSCI, ici comme sur la
 * source vivante.
 *
 * Ce que ces tests continuent de garantir, et qui compte : un enseignant SANS
 * jeton KLASSCI obtient quand même ses classes depuis le miroir, **sans le
 * moindre appel réseau**. Le miroir garde son rôle de repli.
 *
 * @see tests/Feature/Classe/TeacherClassesLiveSourceTest.php — la source vivante
 */
final class TeacherClassesLocalListTest extends TestCase
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

    public function test_teacher_sees_only_assigned_classes_without_http(): void
    {
        $teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
        ]);
        $mine = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'libelle' => 'B2 com',
        ]);
        $other = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'libelle' => 'Autre',
        ]);
        $matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
        ]);
        $mine->matieres()->attach($matiere->id, ['enseignant_id' => $teacher->id]);
        $other->matieres()->attach($matiere->id, ['enseignant_id' => User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
        ])->id]);

        Sanctum::actingAs($teacher);
        $response = $this->getJson('/api/lms/teacher/classes');

        $response->assertOk()->assertJsonPath('success', true);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$mine->klassci_id], $ids);
        Http::assertNothingSent();
    }

    public function test_container_binds_local_enrollment_not_singleton(): void
    {
        $first = app(EnrollmentSource::class);
        $second = app(EnrollmentSource::class);
        $this->assertInstanceOf(EnrollmentSource::class, $first);
        $this->assertNotSame($first, $second);
    }

    public function test_in_memory_fake_is_substitutable(): void
    {
        $teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
        ]);
        $classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
        ]);
        $fake = new InMemoryEnrollmentSource;
        $fake->assign($teacher->id, $classe->id);
        $this->app->instance(EnrollmentSource::class, $fake);

        Sanctum::actingAs($teacher);
        $ids = collect($this->getJson('/api/lms/teacher/classes')->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$classe->klassci_id], $ids);
        Http::assertNothingSent();
    }
}
