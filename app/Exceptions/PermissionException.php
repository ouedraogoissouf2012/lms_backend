<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class PermissionException extends ApiException
{
    protected string $errorCode = 'PERMISSION_DENIED';
    protected int $statusCode = 403;

    protected function getDefaultClientMessage(): string
    {
        return 'Accès refusé';
    }

}
