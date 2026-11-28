<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PorteLEcoleDeSaClasse;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Une inscription d'un compte dans une classe (`classe_etudiant`) : elle appartient à l'école de
 * sa classe, par construction (#801, {@see PorteLEcoleDeSaClasse}).
 *
 * @property int $id
 * @property int $classe_id
 * @property int $user_id
 * @property int $institution_id
 * @property string $statut
 * @property string|null $validation #1658
 * @property string|null $reserve_condition
 * @property string|null $reserve_piece
 * @property string|null $reserve_levee_le
 * @property bool|int|null $redoublant
 * @property string|null $etablissement_origine
 * @property string|null $affectation
 * @property string|null $motif_annulation
 */
final class ClasseEtudiant extends Pivot
{
    use PorteLEcoleDeSaClasse;

    protected $table = 'classe_etudiant';
}
