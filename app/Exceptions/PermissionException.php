<?php

namespace App\Exceptions;

class PermissionException extends ApiException
{
    protected string $errorCode = 'PERMISSION_DENIED';
    protected int $statusCode = 403;

    protected function getDefaultClientMessage(): string
    {
        return 'Accès refusé';
    }
}
