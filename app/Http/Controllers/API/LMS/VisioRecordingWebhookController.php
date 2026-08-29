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
        // `all()` retourne toujours un tableau clé => valeur : le garde `is_array()`
        // était mort et masquait le type réel attendu par accept().
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $result = $this->webhooks->accept(
            $request->getContent(),
            $request->header('X-Visio-Signature'),
            $request->header('X-Visio-Timestamp'),
            $request->header('X-Visio-Nonce'),
            $payload,
        );

        return response()->json($result['payload'], $result['status']);
    }
}
