<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Jobs\GenerateReportPdf;
use App\Models\User;
use Illuminate\Support\Str;

final class AsyncReportDispatcher
{
    public function __construct(
        private readonly AsyncReportStore $store,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array{id: string, status: string, status_url: string, download_url: string}
     */
    public function dispatch(string $type, array $data, User $user): array
    {
        $id = (string) Str::uuid();
        $this->store->pending($id, (int) $user->id, $type, ['filters' => $data]);

        GenerateReportPdf::dispatch($id, $type, $data, (int) $user->id)->onQueue('low');

        return [
            'id' => $id,
            'status' => 'pending',
            'status_url' => route('admin.reports.async.status', ['id' => $id], false),
            'download_url' => route('admin.reports.async.download', ['id' => $id], false),
        ];
    }
}
