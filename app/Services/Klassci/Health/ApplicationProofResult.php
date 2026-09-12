<?php

declare(strict_types=1);

namespace App\Services\Klassci\Health;

/**
 * @phpstan-type ProofKind 'ok'|'pas_klassci'|'klassci_erreur'
 */
final class ApplicationProofResult
{
    /**
     * @param  ProofKind  $kind
     */
    private function __construct(
        public readonly string $kind,
        public readonly int $statusCode,
        public readonly int $elapsedMs,
    ) {}

    public static function ok(int $statusCode, int $elapsedMs): self
    {
        return new self('ok', $statusCode, $elapsedMs);
    }

    public static function notKlassci(int $statusCode, int $elapsedMs): self
    {
        return new self('pas_klassci', $statusCode, $elapsedMs);
    }

    public static function klassciError(int $statusCode, int $elapsedMs): self
    {
        return new self('klassci_erreur', $statusCode, $elapsedMs);
    }

    public function isOk(): bool
    {
        return $this->kind === 'ok';
    }
}
