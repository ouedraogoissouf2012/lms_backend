<?php

declare(strict_types=1);

namespace App\Support\Logging;

use RuntimeException;
use Throwable;

/**
 * Une exception réduite à sa description : ce que le stockage des jobs échoués
 * reçoit à la place de l'exception d'origine.
 *
 * Laravel stocke un job échoué par `(string) $exception`
 * (`DatabaseUuidFailedJobProvider::log()`). Cette chaîne est ici la description
 * de `ExceptionDescription`, plus la trace de tête de l'exception d'origine
 * (sans arguments, `zend.exception_ignore_args`). Son message ne porte que la
 * classe d'origine.
 */
final class DescribedFailure extends RuntimeException
{
    private readonly string $written;

    public function __construct(Throwable $original, ExceptionDescription $description)
    {
        parent::__construct($original::class);

        $this->written = json_encode($description->describe($original), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)
            ."\n".$original->getTraceAsString();
    }

    public function __toString(): string
    {
        return $this->written;
    }
}
