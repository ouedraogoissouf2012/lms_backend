<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;

/**
 * Une pièce à fournir au dossier des élèves d'une école autonome (#1661) : son `code` (unique dans l'école, celui
 * qu'attend une réserve d'inscription), sa forme, sa quantité, sa portée et sa durée de validité. Elle se désactive,
 * elle ne se supprime pas. Chaque écriture est tracée (`Auditable`).
 *
 * @property int $id
 * @property int $institution_id
 * @property string $code
 * @property string $libelle
 * @property string|null $consigne
 * @property string $forme
 * @property int $quantite
 * @property string $portee
 * @property int|null $validite_jours
 * @property bool $obligatoire
 * @property bool $en_service
 */
final class PieceAFournir extends Model
{
    use Auditable;
    use BelongsToInstitution;

    public const FORMES = ['original', 'copie'];

    public const PORTEES = ['une_fois', 'chaque_inscription'];

    protected $table = 'pieces_a_fournir';

    protected $fillable = [
        'institution_id', 'code', 'libelle', 'consigne', 'forme', 'quantite', 'portee', 'validite_jours', 'obligatoire', 'en_service',
    ];

    protected $casts = [
        'institution_id' => 'integer',
        'quantite' => 'integer',
        'validite_jours' => 'integer',
        'obligatoire' => 'boolean',
        'en_service' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['en_service' => true, 'obligatoire' => true, 'quantite' => 1];
}
