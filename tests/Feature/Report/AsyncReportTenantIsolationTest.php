<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Jobs\GenerateReportPdf;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Report\AttendanceReportContextBuilder;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Issue #536 (P0) — Fuite CROSS-TENANT via la génération de rapports ASYNC.
 *
 * En prod (`QUEUE_CONNECTION=database`), `GenerateReportPdf` tourne dans un worker
 * SANS middleware `ResolveInstitution` → `TenantManager::id()` est null →
 * le global scope `BelongsToInstitution` devient no-op → les rapports agrègent
 * TOUS les tenants. Masqué en test car `QUEUE_CONNECTION=sync` exécute le job
 * inline pendant la requête (tenant encore résolu) = « faux vert ».
 *
 * @see app/Jobs/GenerateReportPdf.php
 * @see app/Services/Report/ReportGenerationService.php
 */
final class AsyncReportTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_sets_caller_tenant_before_generating_then_resets(): void
    {
        Storage::fake('local');

        $instA = Institution::factory()->create();
        $caller = User::factory()->create(['institution_id' => $instA->id, 'role' => 'coordinateur']);

        // Espion : enregistre les appels à set() sans mocker le service (final).
        $spy = new class extends TenantManager {
            /** @var array<int, int> */
            public array $setInstitutionIds = [];

            public function set(Institution $institution): void
            {
                $this->setInstitutionIds[] = (int) $institution->id;
                parent::set($institution);
            }
        };
        $spy->reset(); // worker tenant-less au départ
        $this->app->instance(TenantManager::class, $spy);

        // Service RÉEL (on observe le tenant posé par le job, pas ses internes).
        $job = new GenerateReportPdf('rep-536', 'activity', ['date_start' => '2026-01-01', 'date_end' => '2026-12-31'], (int) $caller->id);
        $this->app->call([$job, 'handle']);

        $this->assertContains(
            (int) $instA->id,
            $spy->setInstitutionIds,
            'Le job doit poser le tenant du caller (TenantManager::set) AVANT de générer — sinon fuite cross-tenant.',
        );
        $this->assertNull(
            $spy->id(),
            'Le job doit réinitialiser le tenant après (worker persistant ne doit pas fuiter vers le job suivant).',
        );
    }

    public function test_attendance_context_is_scoped_to_current_tenant(): void
    {
        $instA = Institution::factory()->create();
        $instB = Institution::factory()->create();

        $seanceA = Seance::factory()->create([
            'institution_id' => $instA->id,
            'date_seance' => '2026-03-01 10:00:00',
            'klassci_seance_id' => 1001,
        ]);
        $seanceB = Seance::factory()->create([
            'institution_id' => $instB->id,
            'date_seance' => '2026-03-01 10:00:00',
            'klassci_seance_id' => 2001,
        ]);
        ESBTPAttendance::factory()->count(2)->create(['seance_id' => $seanceA->id]);
        ESBTPAttendance::factory()->count(3)->create(['seance_id' => $seanceB->id]);

        // Tenant courant = A (comme le job le posera pour un caller de A).
        app(TenantManager::class)->set($instA);

        $context = app(AttendanceReportContextBuilder::class)->build([
            'date_start' => '2026-01-01',
            'date_end' => '2026-12-31',
        ]);

        $this->assertSame(2, $context['stats']['total'], 'Le rapport ne doit compter que les présences du tenant courant (A), pas celles de B.');
    }
}
