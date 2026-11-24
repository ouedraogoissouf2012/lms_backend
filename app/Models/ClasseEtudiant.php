<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PorteLEcoleDeSaClasse;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Une inscription d'un compte dans une classe (`classe_etudiant`) : elle appartient à l'école de
 * sa classe, par construction (#801, {@see PorteLEcoleDeSaClasse}).
 */
final class ClasseEtudiant extends Pivot
{
    use PorteLEcoleDeSaClasse;

    protected $table = 'classe_etudiant';
}
