<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\RunsForInstitution;
use App\Models\Import;
use App\Services\Import\ImportApplyService;
use App\Services\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, RunsForInstitution;

    public function __construct(
        private readonly int $importId,
        private readonly int $tenantId,
    ) {
        $this->onQueue('low');
    }

    public function handle(TenantManager $tenants, ImportApplyService $apply): void
    {
        $this->bindTenant($tenants);
        $import = Import::query()->find($this->importId);
        if ($import === null) {
            return;
        }

        try {
            $apply->apply($import);
        } catch (Throwable $e) {
            $import->update(['status' => Import::STATUS_FAILED]);
            throw $e;
        }
    }

    protected function institutionId(): int
    {
        return $this->tenantId;
    }
}
