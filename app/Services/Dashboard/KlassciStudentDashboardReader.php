<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Support\Klassci\KlassciPersonalToken;
use Psr\Log\LoggerInterface;

/**
 * Source KLASSCI du tableau de bord de l'élève : `me/dashboard`, relayé TEL QUEL
 * avec le jeton du compte. C'est le corps de l'ancienne méthode
 * `ProxyDashboardController::studentDashboard`, extrait sans changement.
 *
 * Sans jeton, {@see MissingKlassciTokenException} : le rendu des erreurs du proxy
 * en fait le même 401 `klassci_token_missing` qu'avant.
 */
final class KlassciStudentDashboardReader implements StudentDashboardReader
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {}

    public function dashboardFor(User $student): array
    {
        $klassciToken = KlassciPersonalToken::de($student);
        if ($klassciToken === null) {
            throw MissingKlassciTokenException::forUser($student->id);
        }

        $this->logger->info('Student Dashboard request', [
            'user_id' => $student->id,
            'klassci_id' => $student->klassci_id,
            'has_klassci_token' => true,
        ]);

        return $this->klassciService->requestWithUserToken($klassciToken, 'me/dashboard', 'GET');
    }
}
