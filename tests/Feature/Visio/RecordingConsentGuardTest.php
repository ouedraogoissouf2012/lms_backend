<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Enums\ConsentPurpose;
use App\Models\Consent;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use App\Services\Visio\Recording\RecordingConsentGuard;
use App\Services\Visio\Recording\SeanceRecordingControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * #716 — le consentement précède l'enregistrement ; append-only.
 */
final class RecordingConsentGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_start_without_consent_is_rejected(): void
    {
        [$teacher, $seance] = $this->ownerSeance();

        $result = app(SeanceRecordingControlService::class)->start($seance->id, $teacher);

        self::assertSame(422, $result['status']);
        self::assertSame(
            'Le consentement a la captation doit etre recueilli avant tout enregistrement.',
            $result['payload']['message'],
        );
        self::assertDatabaseCount('seance_recordings', 0);
    }

    public function test_revocation_does_not_delete_the_original_row(): void
    {
        [$teacher, $seance] = $this->ownerSeance();
        $guard = app(RecordingConsentGuard::class);
        $first = $guard->record($teacher, $teacher, ConsentPurpose::Capture, true, $seance);
        $guard->record($teacher, $teacher, ConsentPurpose::Capture, false, $seance);

        self::assertDatabaseCount('consents', 2);
        self::assertDatabaseHas('consents', ['id' => $first->id, 'granted' => 1]);
        self::assertFalse($guard->allowsStart($seance, $teacher));
    }

    public function test_consent_state_can_be_rebuilt_at_a_given_date(): void
    {
        [$teacher, $seance] = $this->ownerSeance();
        $guard = app(RecordingConsentGuard::class);
        $guard->record($teacher, $teacher, ConsentPurpose::Capture, true, $seance);
        $whenGranted = now();
        $this->travel(2)->hours();
        $guard->record($teacher, $teacher, ConsentPurpose::Capture, false, $seance);

        self::assertTrue($guard->grantedAt($teacher, ConsentPurpose::Capture, $seance, $whenGranted));
        self::assertFalse($guard->allowsStart($seance, $teacher));
    }

    public function test_default_layout_is_teacher_view_and_screen_share_only(): void
    {
        $layout = app(RecordingConsentGuard::class)->defaultLayout();

        self::assertTrue($layout['capture_teacher_view']);
        self::assertTrue($layout['capture_screen_share']);
        self::assertFalse($layout['capture_learner_tiles']);
        self::assertFalse($layout['capture_learner_audio']);
    }

    public function test_consent_rows_cannot_be_updated(): void
    {
        [$teacher, $seance] = $this->ownerSeance();
        $row = app(RecordingConsentGuard::class)
            ->record($teacher, $teacher, ConsentPurpose::Capture, true, $seance);

        $this->expectException(LogicException::class);
        $row->update(['granted' => false]);
    }

    /**
     * @return array{0: User, 1: Seance}
     */
    private function ownerSeance(): array
    {
        $institution = Institution::factory()->create();
        app(TenantManager::class)->set($institution);
        $teacher = User::factory()->teacher()->for($institution)->create([
            'klassci_id' => 7161,
            'klassci_enseignant_id' => 7161,
        ]);
        $seance = Seance::factory()->forInstitution($institution)->visioActive()->create([
            'klassci_enseignant_id' => 7161,
        ]);

        return [$teacher, $seance];
    }
}
