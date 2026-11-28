<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * #1677 (B1) : le modèle de bulletin d'une école — l'en-tête, les signataires, le barème des
 * appréciations, les blocs de matières et les mentions affichées. Un par école.
 *
 * @property int $id
 * @property int $institution_id
 * @property list<string>|null $entete
 * @property list<array{fonction: string, nom: string}>|null $signataires
 * @property list<array{min: float, max: float, libelle: string}>|null $bareme
 * @property list<array{libelle: string, matiere_ids: list<int>}>|null $blocs
 * @property array<string, bool>|null $mentions
 */
class ModeleDeBulletin extends Model
{
    use Auditable, BelongsToInstitution;

    protected $table = 'modeles_de_bulletin';

    protected $fillable = ['institution_id', 'entete', 'signataires', 'bareme', 'blocs', 'mentions'];

    protected $casts = [
        'institution_id' => 'integer',
        'entete' => 'array',
        'signataires' => 'array',
        'bareme' => 'array',
        'blocs' => 'array',
        'mentions' => 'array',
    ];
}
