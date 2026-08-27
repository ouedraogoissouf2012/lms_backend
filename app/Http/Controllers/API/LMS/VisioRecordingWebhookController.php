<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\Controller;
use App\Services\Visio\Recording\SeanceRecordingWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #469 — webhook fournisseur (JaaS/Jibri). Pas de Bearer : HMAC.
 */
final class VisioRecordingWebhookController extends Controller
{
    public function __construct(
        private readonly SeanceRecordingWebhookService $webhooks,
    ) {
    }

    public function recordingReady(Request $request): JsonResponse
    {
        $result = $this->webhooks->accept(
            $request->getContent(),
            $request->header('X-Visio-Signature'),
            $request->header('X-Visio-Timestamp'),
            $request->header('X-Visio-Nonce'),
            is_array($request->all()) ? $request->all() : [],
        );

        return response()->json($result['payload'], $result['status']);
    }
}
