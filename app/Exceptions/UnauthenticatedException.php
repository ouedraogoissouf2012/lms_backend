<?php

namespace App\Exceptions;

class UnauthenticatedException extends ApiException
{
    protected string $errorCode = 'UNAUTHENTICATED';
    protected int $statusCode = 401;

    protected function getDefaultClientMessage(): string
    {
        return 'Authentification requise';
    }
}
