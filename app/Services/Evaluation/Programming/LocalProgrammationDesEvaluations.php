<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Programming;

use App\Models\User;
use App\Support\Catalogue\SourceLocale;

/**
 * La programmation des évaluations dans une école créée depuis le LMS : il n'existe
 * pas de programmation KLASSCI, la liste est donc VIDE. Ni 401, ni appel à KLASSCI,
 * même pour un compte porteur d'un jeton. L'origine est dite (D172).
 */
final class LocalProgrammationDesEvaluations implements ProgrammationDesEvaluationsReader
{
    public function list(User $user, array $filtres): array
    {
        return ['success' => true, 'data' => [], 'meta' => ['source' => SourceLocale::LMS_LOCAL]];
    }
}
