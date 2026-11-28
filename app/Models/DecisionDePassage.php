<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * La décision de passage d'un élève dans une classe (#1663) : la proposition calculée qu'elle corrige, la décision du
 * personnel et son motif. Une par élève et par classe ; auditée.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $classe_id
 * @property int $user_id
 * @property string|null $proposition
 * @property string $decision
 * @property string $motif
 * @property int|null $decide_par
 * @property Carbon|null $updated_at
 */
final class DecisionDePassage extends Model
{
    use Auditable;
    use BelongsToInstitution;

    public const PASSE = 'passe';

    public const RATTRAPAGE = 'rattrapage';

    public const REDOUBLE = 'redouble';

    public const EXCLU = 'exclu';

    /** Les décisions possibles, dans l'ordre des compteurs. */
    public const DECISIONS = [self::PASSE, self::RATTRAPAGE, self::REDOUBLE, self::EXCLU];

    protected $table = 'decisions_de_passage';

    protected $fillable = ['institution_id', 'classe_id', 'user_id', 'proposition', 'decision', 'motif', 'decide_par'];

    protected $casts = [
        'institution_id' => 'integer',
        'classe_id' => 'integer',
        'user_id' => 'integer',
        'decide_par' => 'integer',
    ];
}
