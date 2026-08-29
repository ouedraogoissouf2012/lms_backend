<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Institution;
use App\Models\User;
use App\Services\Report\AsyncReportStore;
use App\Services\Report\ReportGenerationService;
use App\Services\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;
use Throwable;

final class GenerateReportPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $reportId,
        private readonly string $type,
        private readonly array $data,
        private readonly int $userId,
    ) {
    }

    public function handle(
        ReportGenerationService $reports,
        AsyncReportStore $store,
        LoggerInterface $logger,
        TenantManager $tenantManager,
    ): void {
        $store->running($this->reportId);

        // #536 — Le worker de queue n'exécute PAS le middleware ResolveInstitution :
        // sans réapplication explicite, TenantManager::id() est null → le global scope
        // BelongsToInstitution devient no-op → le rapport agrège TOUS les tenants
        // (fuite cross-tenant). On repose donc le tenant du demandeur, comme le fait
        // le middleware en synchrone. Un demandeur SANS institution (supradmin
        // plateforme) reste volontairement non scopé (voit tout).
        // reset() au début : sur un worker persistant, purge un tenant résiduel d'un
        // job précédent (sinon un rapport supradmin serait scopé par erreur).
        $tenantManager->reset();

        try {
            // Lookup hors scope : robuste même si un tenant résiduel traîne.
            $user = User::withoutGlobalScope('institution')->findOrFail($this->userId);

            if ($user->institution_id !== null) {
                $institution = Institution::find($user->institution_id);
                if ($institution !== null) {
                    $tenantManager->set($institution);
                }
            }

            $result = $this->generate($reports, $user);
            $payload = $result['payload'];

            if ($result['status'] !== 200 || ! $payload instanceof Response) {
                $store->failed($this->reportId, 'report_generation_failed');
                return;
            }

            $path = 'reports/async/' . $this->reportId . '.pdf';
            Storage::disk('local')->put($path, (string) $payload->getContent());
            $store->completed($this->reportId, $path, $this->filename());
        } catch (Throwable $e) {
            $store->failed($this->reportId, 'report_generation_failed');
            $logger->error('Async report generation failed', [
                'report_id' => $this->reportId,
                'type' => $this->type,
                'user_id' => $this->userId,
                'exception' => $e::class,
            ]);

            throw $e;
        } finally {
            // Ne pas fuiter le tenant vers le prochain job du worker.
            $tenantManager->reset();
        }
    }

    public function failed(Throwable $exception): void
    {
        app(AsyncReportStore::class)->failed($this->reportId, 'report_generation_failed');
    }

    /**
     * @return array{status: int, payload: Response|array<string, mixed>}
     */
    private function generate(ReportGenerationService $reports, User $user): array
    {
        return match ($this->type) {
            'attendance' => $reports->generateAttendance($this->data, $user),
            'grades' => $reports->generateGrades($this->data, $user),
            'activity' => $reports->generateActivity($this->data, $user),
            default => ['status' => 422, 'payload' => ['success' => false]],
        };
    }

    private function filename(): string
    {
        return 'rapport-' . $this->type . '-' . now()->format('Y-m-d') . '.pdf';
    }
}
