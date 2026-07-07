<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Report\AsyncReportStore;
use App\Services\Report\ReportGenerationService;
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
    ): void {
        $store->running($this->reportId);

        try {
            $user = User::findOrFail($this->userId);
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
