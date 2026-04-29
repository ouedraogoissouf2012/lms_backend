<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class ValidationException extends ApiException
{
    protected string $errorCode = 'VALIDATION_FAILED';
    protected int $statusCode = 422;
    private array $errors = [];

    public function __construct(
        array $errors,
        string $clientMessage = null,
        string $logMessage = null,
        array $context = []
    ) {
        $this->errors = $errors;
        parent::__construct($clientMessage, $logMessage, 422, $context);
    }

    protected function getDefaultClientMessage(): string
    {
        return 'Erreur de validation';
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => $this->getErrorCode(),
            'message' => $this->getClientMessage(),
            'errors' => $this->getErrors(),
        ], $this->getStatusCode());
    }
}
