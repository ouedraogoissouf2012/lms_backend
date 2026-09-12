<?php

declare(strict_types=1);

namespace App\Services\Klassci\Health;

/**
 * Preuve applicative (#713) : le serveur parle-t-il comme `/auth/check-user` ?
 * Distinct de {@see KlassciReachability}, qui ne mesure que le transport (un 404
 * y reste « joignable »).
 */
interface KlassciApplicationProof
{
    public function verify(string $baseUrl): ApplicationProofResult;
}
