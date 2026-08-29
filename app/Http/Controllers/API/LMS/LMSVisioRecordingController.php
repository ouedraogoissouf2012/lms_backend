<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Visio\Recording\SeanceRecordingControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LMSVisioRecordingController extends AuthenticatedController
{
    public function __construct(private readonly SeanceRecordingControlService $recordings) {}

    public function start(int $seanceId, Request $request): JsonResponse
    {
        $result = $this->recordings->start($seanceId, $this->authenticatedUser($request));

        return response()->json($result['payload'], $result['status']);
    }

    public function stop(int $seanceId, Request $request): JsonResponse
    {
        $result = $this->recordings->stop($seanceId, $this->authenticatedUser($request));

        return response()->json($result['payload'], $result['status']);
    }

    public function show(int $seanceId, Request $request): JsonResponse
    {
        $result = $this->recordings->status($seanceId, $this->authenticatedUser($request));

        return response()->json($result['payload'], $result['status']);
    }
}
