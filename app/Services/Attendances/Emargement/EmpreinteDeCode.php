<?php

declare(strict_types=1);

namespace App\Services\Attendances\Emargement;

use App\Services\Enrollment\ClasseEnrolmentCode;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * L'empreinte d'un code d'émargement (#1680) : HMAC-SHA256 du code NORMALISÉ, avec la clé de l'application.
 *
 * Déterministe, pour retrouver le code saisi sans le garder en clair. Un simple SHA-256 d'un code de 6 caractères
 * (31⁶ ≈ 887 millions) se renverserait par force brute hors ligne ; la clé de l'application l'interdit à qui lirait la
 * base sans elle. La casse et les espaces sont normalisés AVANT, par la règle du code d'inscription.
 */
final class EmpreinteDeCode
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function de(string $code): string
    {
        $cle = $this->config->get('app.key');
        if (! is_string($cle) || $cle === '') {
            throw new RuntimeException('Clé de l\'application absente : aucune empreinte de code possible.');
        }

        return hash_hmac('sha256', ClasseEnrolmentCode::normaliser($code), $cle);
    }
}
