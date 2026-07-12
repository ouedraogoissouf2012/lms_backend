<?php

namespace Tests\Feature\Reports;

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

class AttendanceReportGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_visio_statuses_are_converted_to_attendance_report_counts(): void
    {
        Carbon::setTestNow('2026-07-06 12:00:00');
        $institution = $this->tenant();
        $admin = User::factory()->admin()->create(['institution_id' => $institution->id]);
        $presentStudent = User::factory()->student()->create([
            'institution_id' => $institution->id,
            'name' => 'Awa Traore',
            'email' => 'awa@example.test',
        ]);
        $disconnectedStudent = User::factory()->student()->create(['institution_id' => $institution->id]);
        $kickedStudent = User::factory()->student()->create(['institution_id' => $institution->id]);
        $seance = Seance::factory()->visioEnded()->create([
            'institution_id' => $institution->id,
            'date_seance' => '2026-07-06',
        ]);

        $this->attendance($seance, $presentStudent, 'connected', $institution);
        $this->attendance($seance, $disconnectedStudent, 'disconnected', $institution);
        $this->attendance($seance, $kickedStudent, 'kicked', $institution);

        $pdf = new ReportCapturingPdf;
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
        $this->assertSame(3, $pdf->stat('total'));
        $this->assertSame(2, $pdf->stat('presents'));
        $this->assertSame(1, $pdf->stat('absents'));
        $this->assertSame(0, $pdf->stat('retards'));
        $this->assertSame(66.67, $pdf->stat('taux_presence'));
        $this->assertSame('Awa Traore', $pdf->firstStudentField('name'));
        $this->assertSame(1, $pdf->firstStudentField('presents'));
        $this->assertSame(0, $pdf->firstStudentField('absents'));
        $this->assertSame(100.0, $pdf->firstStudentField('taux'));
    }

    private function tenant(): Institution
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

    private function attendance(
        Seance $seance,
        User $student,
        string $status,
        Institution $institution,
    ): ESBTPAttendance {
        return ESBTPAttendance::factory()->create([
            'seance_id' => $seance->id,
            'user_id' => $student->id,
            'institution_id' => $institution->id,
            'status' => $status,
        ]);
    }
}

class ReportCapturingPdf extends PDF
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

    public function stat(string $key): mixed
    {
        $stats = $this->context['stats'] ?? null;
        if (! is_array($stats)) {
            return null;
        }

        return $stats[$key] ?? null;
    }

    public function firstStudentField(string $key): mixed
    {
        $students = $this->context['students'] ?? null;
        if (! $students instanceof Collection) {
            return null;
        }

        $student = $students->first();
        if (! is_array($student)) {
            return null;
        }

        return $student[$key] ?? null;
    }
}
