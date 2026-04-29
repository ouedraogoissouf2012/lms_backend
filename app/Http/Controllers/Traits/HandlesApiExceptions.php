<?php

namespace App\Http\Controllers\Traits;

use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\PermissionException;
use App\Exceptions\UnauthenticatedException;
use App\Exceptions\ValidationException;
use App\Exceptions\ApiServerException;

/**
 * Trait pour uniformiser la gestion des exceptions dans les contrôleurs API
 * Usage: use HandlesApiExceptions; puis throw $this->notFound(...);
 */
trait HandlesApiExceptions
{
    /**
     * Lance une exception 404 Resource Not Found
     */
    protected function notFound(string $resource, string $logMessage = null, array $context = []): void
    {
        throw new ResourceNotFoundException(
            clientMessage: "{$resource} non trouvée",
            logMessage: $logMessage ?? "{$resource} not found",
            context: array_merge(['resource' => $resource], $context)
        );
    }

    /**
     * Lance une exception 403 Permission Denied
     */
    protected function forbidden(string $message = null, string $logMessage = null, array $context = []): void
    {
        throw new PermissionException(
            clientMessage: $message ?? 'Accès refusé',
            logMessage: $logMessage ?? 'Permission denied',
            context: $context
        );
    }

    /**
     * Lance une exception 401 Unauthenticated
     */
    protected function unauthenticated(string $message = null, string $logMessage = null, array $context = []): void
    {
        throw new UnauthenticatedException(
            clientMessage: $message ?? 'Authentification requise',
            logMessage: $logMessage ?? 'Unauthenticated',
            context: $context
        );
    }

    /**
     * Lance une exception 422 Validation Failed
     */
    protected function validationFailed(array $errors, string $message = null, string $logMessage = null, array $context = []): void
    {
        throw new ValidationException(
            errors: $errors,
            clientMessage: $message ?? 'Erreur de validation',
            logMessage: $logMessage ?? 'Validation failed',
            context: array_merge(['error_count' => count($errors)], $context)
        );
    }

    /**
     * Lance une exception 500 Server Error
     */
    protected function serverError(string $logMessage, \Throwable $exception = null, array $context = []): void
    {
        throw new ApiServerException(
            clientMessage: 'Erreur interne du serveur',
            logMessage: $logMessage . ($exception ? ' - ' . $exception->getMessage() : ''),
            context: array_merge(
                $exception ? ['exception' => class_basename($exception)] : [],
                $context
            )
        );
    }
}
