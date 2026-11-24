<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PorteLEcoleDeSaClasse;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Une matière au programme d'une classe (`classe_matiere`) : elle appartient à l'école de sa
 * classe, par construction (#801, {@see PorteLEcoleDeSaClasse}).
 */
final class ClasseMatiere extends Pivot
{
    use PorteLEcoleDeSaClasse;

    protected $table = 'classe_matiere';
}
