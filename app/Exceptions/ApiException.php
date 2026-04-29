<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Base exception pour les erreurs API
 * Toutes les exceptions métier héritent de celle-ci
 */
abstract class ApiException extends Exception
{
    protected string $errorCode;
    protected string $clientMessage;
    protected int $statusCode = 500;
    protected array $context = [];

    public function __construct(
        string $clientMessage = null,
        string $logMessage = null,
        int $statusCode = null,
        array $context = []
    ) {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }
        $this->clientMessage = $clientMessage ?? $this->getDefaultClientMessage();
        $this->context = $context;

        parent::__construct($logMessage ?? $this->clientMessage);
    }

    abstract protected function getDefaultClientMessage(): string;

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getClientMessage(): string
    {
        return $this->clientMessage;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => $this->getErrorCode(),
            'message' => $this->getClientMessage(),
        ], $this->getStatusCode());
    }
}
