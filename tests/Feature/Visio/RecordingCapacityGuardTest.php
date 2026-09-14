<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Enums\ConsentPurpose;
use App\Enums\SeanceRecordingStatus;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceRecording;
use App\Models\User;
use App\Services\TenantManager;
use App\Services\Visio\Recording\RecordingCapacityGuard;
use App\Services\Visio\Recording\RecordingConsentGuard;
use App\Services\Visio\Recording\SeanceRecordingControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #706 — la capacité d'enregistrement de la plateforme est finie.
 *
 * Jibri : « Only one recording at a time is supported on a single jibri ». Le
 * verrou existant est par séance ; celui-ci est global, et il ignore
 * délibérément le scope tenant.
 */
final class RecordingCapacityGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_second_simultaneous_recording_is_refused_when_capacity_is_one(): void
    {
        config(['recordings.max_concurrent' => 1]);
        $institution = $this->institution();
        [$firstTeacher, $firstSeance] = $this->ownerSeance($institution, 706011);
        [$secondTeacher, $secondSeance] = $this->ownerSeance($institution, 706012);

        $first = app(SeanceRecordingControlService::class)->start($firstSeance->id, $firstTeacher);
        self::assertSame(200, $first['status']);

        $second = app(SeanceRecordingControlService::class)->start($secondSeance->id, $secondTeacher);

        self::assertSame(409, $second['status']);
        self::assertSame(
            'Un autre enregistrement est en cours sur la plateforme. Reessayez dans quelques minutes.',
            $second['payload']['message'],
        );

        // Aucune ligne vouée à l'échec silencieux : c'est tout l'objet du garde.
        self::assertDatabaseCount('seance_recordings', 1);
        self::assertDatabaseMissing('seance_recordings', ['seance_id' => $secondSeance->id]);
    }

    /**
     * LE test de l'issue : `SeanceRecording` porte `BelongsToInstitution`, donc
     * un comptage nominal serait scopé au tenant et l'école B démarrerait
     * pendant que l'école A enregistre. La capacité est une propriété
     * d'infrastructure, pas du tenant.
     */
    public function test_capacity_is_shared_across_institutions(): void
    {
        config(['recordings.max_concurrent' => 1]);
        $institutionA = $this->institution();
        [$teacherA, $seanceA] = $this->ownerSeance($institutionA, 706021);

        $started = app(SeanceRecordingControlService::class)->start($seanceA->id, $teacherA);
        self::assertSame(200, $started['status']);

        $institutionB = $this->institution();
        [$teacherB, $seanceB] = $this->ownerSeance($institutionB, 706022);

        $refused = app(SeanceRecordingControlService::class)->start($seanceB->id, $teacherB);

        self::assertSame(409, $refused['status']);
        self::assertDatabaseCount('seance_recordings', 1);
    }

    public function test_nth_recording_is_accepted_and_n_plus_one_is_refused(): void
    {
        config(['recordings.max_concurrent' => 2]);
        $institution = $this->institution();
        [$teacherOne, $seanceOne] = $this->ownerSeance($institution, 706031);
        [$teacherTwo, $seanceTwo] = $this->ownerSeance($institution, 706032);
        [$teacherThree, $seanceThree] = $this->ownerSeance($institution, 706033);

        $service = app(SeanceRecordingControlService::class);

        self::assertSame(200, $service->start($seanceOne->id, $teacherOne)['status']);
        self::assertSame(200, $service->start($seanceTwo->id, $teacherTwo)['status']);
        self::assertSame(409, $service->start($seanceThree->id, $teacherThree)['status']);

        self::assertDatabaseCount('seance_recordings', 2);
    }

    /**
     * Une reprise après rechargement de page ne consomme pas un second créneau :
     * la séance occupe déjà le sien. Sans cette exclusion, l'enseignant serait
     * refusé sur son propre enregistrement en cours.
     */
    public function test_a_seance_already_holding_a_slot_can_resume(): void
    {
        config(['recordings.max_concurrent' => 1]);
        $institution = $this->institution();
        [$teacher, $seance] = $this->ownerSeance($institution, 706041);
        $service = app(SeanceRecordingControlService::class);

        self::assertSame(200, $service->start($seance->id, $teacher)['status']);
        $resumed = $service->start($seance->id, $teacher);

        self::assertSame(200, $resumed['status']);
        self::assertDatabaseCount('seance_recordings', 1);
    }

    /**
     * Un enregistrement terminé rend son créneau. Sans cela, la plateforme se
     * bloquerait définitivement après le premier cours.
     */
    public function test_a_finished_recording_releases_its_slot(): void
    {
        config(['recordings.max_concurrent' => 1]);
        $institution = $this->institution();
        [$firstTeacher, $firstSeance] = $this->ownerSeance($institution, 706051);
        [$secondTeacher, $secondSeance] = $this->ownerSeance($institution, 706052);
        $service = app(SeanceRecordingControlService::class);

        $service->start($firstSeance->id, $firstTeacher);
        SeanceRecording::query()->withoutGlobalScope('institution')
            ->where('seance_id', $firstSeance->id)
            ->update(['status' => SeanceRecordingStatus::Ready, 'active_lock_key' => null]);

        self::assertSame(200, $service->start($secondSeance->id, $secondTeacher)['status']);
    }

    public function test_capacity_never_falls_below_one(): void
    {
        config(['recordings.max_concurrent' => 0]);

        self::assertSame(1, app(RecordingCapacityGuard::class)->capacity());
    }

    private function institution(): Institution
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);

        return $institution;
    }

    /**
     * @return array{0: User, 1: Seance}
     */
    private function ownerSeance(Institution $institution, int $klassciId): array
    {
        app(TenantManager::class)->set($institution);

        $teacher = User::factory()->teacher()->for($institution)->create([
            'klassci_id' => $klassciId,
            'klassci_enseignant_id' => $klassciId,
        ]);
        $seance = Seance::factory()->forInstitution($institution)->visioActive()->create([
            'klassci_enseignant_id' => $klassciId,
        ]);

        app(RecordingConsentGuard::class)->record($teacher, $teacher, ConsentPurpose::Capture, true, $seance);

        return [$teacher, $seance];
    }
}
