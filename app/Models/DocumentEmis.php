<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeDeDocument;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un document de scolarité émis par une école (#1662). Il ne change plus après son émission : `contenu` est
 * l'instantané de ce qui a été imprimé.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $eleve_id
 * @property TypeDeDocument $type
 * @property string $numero
 * @property string $code_verification
 * @property array{ecole: string, titulaire: string, classe: string, periode: string|null, inscrit_le: string|null} $contenu
 * @property int|null $emis_par
 * @property Carbon $created_at
 */
class DocumentEmis extends Model
{
    use BelongsToInstitution;

    protected $table = 'documents_emis';

    protected $fillable = ['institution_id', 'eleve_id', 'type', 'numero', 'code_verification', 'contenu', 'emis_par'];

    protected $casts = [
        'type' => TypeDeDocument::class,
        'contenu' => 'array',
    ];
}
