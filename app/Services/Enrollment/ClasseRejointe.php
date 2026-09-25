<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Models\Classe;

/**
 * La classe rejointe et l'issue de l'adhésion (#885) : la porte a besoin des
 * deux pour choisir entre 201 et 200 et nommer la classe dans sa réponse.
 */
final class ClasseRejointe
{
    public function __construct(
        public readonly Classe $classe,
        public readonly Adhesion $adhesion,
    ) {}
}
