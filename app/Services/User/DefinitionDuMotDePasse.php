<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;

/**
 * L'administration définit le mot de passe d'un compte de son école (#1275), selon l'école.
 *
 * Le mot de passe ne figure jamais ni dans la réponse ni dans un journal. Liée par école au
 * point de liaison (`AuthPasswordServiceProvider`, ADR-EI-01).
 */
interface DefinitionDuMotDePasse
{
    public function definir(User $cible, string $motDePasse): void;
}
