<?php

namespace App\Exceptions;

class ResourceNotFoundException extends ApiException
{
    protected string $errorCode = 'RESOURCE_NOT_FOUND';
    protected int $statusCode = 404;

    protected function getDefaultClientMessage(): string
    {
        return 'Ressource non trouvée';
    }
}
