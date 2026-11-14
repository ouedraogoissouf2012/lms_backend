<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Programming;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Support\Klassci\KlassciPersonalToken;

/**
 * La programmation des évaluations dans une école KLASSCI : le relais d'avant,
 * déplacé sans changement depuis `ProxyAcademicController::evaluations`. Un jeton
 * absent lève {@see MissingKlassciTokenException}, rendue en 401
 * `klassci_token_missing` comme avant.
 */
final class KlassciProgrammationDesEvaluations implements ProgrammationDesEvaluationsReader
{
    public function __construct(private readonly KlassciProxyService $klassci) {}

    public function list(User $user, array $filtres): array
    {
        $jeton = KlassciPersonalToken::de($user) ?? throw MissingKlassciTokenException::forUser($user->id);

        return $this->klassci->getEvaluations($jeton, $filtres);
    }
}
