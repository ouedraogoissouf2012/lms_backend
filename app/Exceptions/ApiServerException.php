<?php

namespace App\Exceptions;

class ApiServerException extends ApiException
{
    protected string $errorCode = 'INTERNAL_SERVER_ERROR';
    protected int $statusCode = 500;

    protected function getDefaultClientMessage(): string
    {
        return 'Erreur interne du serveur';
    }
}
