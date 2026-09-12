<?php

declare(strict_types=1);

namespace App\Services\Klassci\Health;

/**
 * Fake mémoire (#713 art. 5).
 */
final class InMemoryKlassciApplicationProof implements KlassciApplicationProof
{
    /** @var array<string, ApplicationProofResult> */
    private array $results = [];

    public function willReturn(string $baseUrl, ApplicationProofResult $result): self
    {
        $this->results[$baseUrl] = $result;

        return $this;
    }

    public function verify(string $baseUrl): ApplicationProofResult
    {
        return $this->results[$baseUrl] ?? ApplicationProofResult::notKlassci(0, 0);
    }
}
