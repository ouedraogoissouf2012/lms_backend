<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Filiere;
use App\Models\NiveauEtude;

/**
 * Les deux éléments de la structure d'une école (#1648) : une filière, un niveau d'études. Le même cycle
 * (créer, modifier, lister, supprimer), deux tables ; ce que chacun a de propre est dit ici, une fois.
 */
enum ElementDeStructure: string
{
    case Filiere = 'filiere';
    case Niveau = 'niveau';

    /** @return class-string<Filiere|NiveauEtude> */
    public function modele(): string
    {
        return match ($this) {
            self::Filiere => Filiere::class,
            self::Niveau => NiveauEtude::class,
        };
    }

    /** Le motif d'un code déjà employé, lu par le front. */
    public function motifDuCodePris(): string
    {
        return match ($this) {
            self::Filiere => 'field_code_already_used',
            self::Niveau => 'level_code_already_used',
        };
    }

    public function texteDuCodePris(): string
    {
        return match ($this) {
            self::Filiere => 'Ce code est déjà employé par une autre filière de l\'établissement.',
            self::Niveau => 'Ce code est déjà employé par un autre niveau d\'études de l\'établissement.',
        };
    }

    public function introuvable(): string
    {
        return match ($this) {
            self::Filiere => 'Filière introuvable dans cet établissement.',
            self::Niveau => 'Niveau d\'études introuvable dans cet établissement.',
        };
    }

    /** Seul un niveau porte un ordre. */
    public function porteUnOrdre(): bool
    {
        return $this === self::Niveau;
    }
}
