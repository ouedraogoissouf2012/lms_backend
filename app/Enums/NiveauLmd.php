<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\LmdDomaine;
use App\Models\LmdMention;
use App\Models\LmdParcours;

/**
 * Les trois niveaux de l'arbre LMD (#1654) : domaine → mention → parcours. Ce que chacun a de propre (son modèle,
 * son parent) est dit ici, une fois ; le service les traite d'un même geste.
 */
enum NiveauLmd: string
{
    case Domaine = 'domaine';
    case Mention = 'mention';
    case Parcours = 'parcours';

    /** @return class-string<LmdDomaine|LmdMention|LmdParcours> */
    public function modele(): string
    {
        return match ($this) {
            self::Domaine => LmdDomaine::class,
            self::Mention => LmdMention::class,
            self::Parcours => LmdParcours::class,
        };
    }

    /** La colonne du parent, `null` pour un domaine. */
    public function colonneDuParent(): ?string
    {
        return match ($this) {
            self::Domaine => null,
            self::Mention => 'domaine_id',
            self::Parcours => 'mention_id',
        };
    }

    /** Le niveau du parent, `null` pour un domaine. */
    public function parent(): ?self
    {
        return match ($this) {
            self::Domaine => null,
            self::Mention => self::Domaine,
            self::Parcours => self::Mention,
        };
    }
}
