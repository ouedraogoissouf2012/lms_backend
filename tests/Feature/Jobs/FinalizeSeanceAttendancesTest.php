<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FinalizeSeanceAttendances;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Report\AttendanceReportContextBuilder;
use App\Services\Report\ReportGenerationService;
use App\Services\TenantManager;
use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Psr\Log\NullLogger;
use Tests\TestCase;

class FinalizeSeanceAttendancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finalizes_connected_participants_after_visio_grace_period(): void
    {
        Carbon::setTestNow('2026-07-06 12:00:00');
        $institution = $this->resolveTenant();
        $student = User::factory()->student()->create(['institution_id' => $institution->id]);
        $seance = $this->endedSeance($institution, endedAt: now()->subMinutes(45));

        $attendance = ESBTPAttendance::factory()->connected()->create([
            'seance_id' => $seance->id,
            'user_id' => $student->id,
            'institution_id' => $institution->id,
            'joined_at' => now()->subMinutes(90),
            'last_seen_at' => now()->subMinutes(50),
        ]);

        (new FinalizeSeanceAttendances)->handle(new NullLogger);

        $attendance->refresh();
        $this->assertSame('disconnected', $attendance->status);
        $this->assertTrue($attendance->left_at?->equalTo(now()->subMinutes(50)));
        $this->assertSame(40, $attendance->duration_minutes);
    }

    public function test_it_keeps_recently_ended_visios_within_grace_period_connected(): void
    {
        Carbon::setTestNow('2026-07-06 12:00:00');
        $institution = $this->resolveTenant();
        $student = User::factory()->student()->create(['institution_id' => $institution->id]);
        $seance = $this->endedSeance($institution, endedAt: now()->subMinutes(10));

        $attendance = ESBTPAttendance::factory()->connected()->create([
            'seance_id' => $seance->id,
            'user_id' => $student->id,
            'institution_id' => $institution->id,
        ]);

        (new FinalizeSeanceAttendances)->handle(new NullLogger);

        $attendance->refresh();
        $this->assertSame('connected', $attendance->status);
        $this->assertNull($attendance->left_at);
    }

    public function test_attendance_report_includes_finalized_disconnected_participant(): void
    {
        Carbon::setTestNow('2026-07-06 12:00:00');
        $institution = $this->resolveTenant();
        $student = User::factory()->student()->create([
            'institution_id' => $institution->id,
            'name' => 'Awa Traore',
            'email' => 'awa@example.test',
        ]);
        $admin = User::factory()->admin()->create(['institution_id' => $institution->id]);
        $seance = $this->endedSeance($institution, endedAt: now()->subMinutes(45));

        ESBTPAttendance::factory()->connected()->create([
            'seance_id' => $seance->id,
            'user_id' => $student->id,
            'institution_id' => $institution->id,
            'joined_at' => now()->subMinutes(90),
            'last_seen_at' => now()->subMinutes(50),
        ]);

        (new FinalizeSeanceAttendances)->handle(new NullLogger);

        $pdf = new CapturingPdf;
        $service = new ReportGenerationService(
            $pdf,
            new NullLogger,
            new AttendanceReportContextBuilder,
        );
        $result = $service->generateAttendance([
            'date_start' => '2026-07-06',
            'date_end' => '2026-07-06',
        ], $admin);

        $this->assertSame(200, $result['status']);
        $this->assertSame('reports.attendance', $pdf->loadedView);
        $this->assertSame(1, $pdf->attendanceTotal());
        $this->assertSame('Awa Traore', $pdf->firstStudentName());
        $this->assertSame(1, $pdf->firstStudentTotal());
    }

    private function resolveTenant(): Institution
    {
        $institution = Institution::create([
            'slug' => 'school-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => 'School Test',
            'klassci_api_url' => 'https://klassci.test',
            'klassci_api_token_encrypted' => 'token',
            'logo_url' => 'https://example.test/logo.png',
            'primary_color' => '#000000',
            'is_active' => true,
            'settings' => ['timezone' => 'UTC'],
        ]);
        app(TenantManager::class)->set($institution);

        return $institution;
    }

    private function endedSeance(Institution $institution, Carbon $endedAt): Seance
    {
        return Seance::factory()->visioEnded()->create([
            'institution_id' => $institution->id,
            'date_seance' => $endedAt->toDateString(),
            'visio_started_at' => $endedAt->copy()->subHour(),
            'visio_ended_at' => $endedAt,
            'is_active' => true,
        ]);
    }
}

class CapturingPdf extends PDF
{
    public string $loadedView = '';

    /**
     * @var array<string, mixed>
     */
    public array $context = [];

    public function __construct() {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $mergeData
     */
    public function loadView(
        string $view,
        array $data = [],
        array $mergeData = [],
        ?string $encoding = null,
    ): self {
        $this->loadedView = $view;
        $this->context = $data;

        return $this;
    }

    public function download(string $filename = 'document.pdf'): Response
    {
        return response('pdf');
    }

    public function attendanceTotal(): ?int
    {
        $stats = $this->context['stats'] ?? null;
        if (! is_array($stats)) {
            return null;
        }

        $total = $stats['total'] ?? null;

        return is_int($total) ? $total : null;
    }

    public function firstStudentName(): ?string
    {
        $student = $this->firstStudent();
        $name = $student['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    public function firstStudentTotal(): ?int
    {
        $student = $this->firstStudent();
        $total = $student['total'] ?? null;

        return is_int($total) ? $total : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstStudent(): array
    {
        $students = $this->context['students'] ?? null;
        if (! $students instanceof Collection) {
            return [];
        }

        $student = $students->first();

        return is_array($student) ? $student : [];
    }
}
