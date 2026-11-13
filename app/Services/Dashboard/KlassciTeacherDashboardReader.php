<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Support\Klassci\KlassciPersonalToken;
use Psr\Log\LoggerInterface;

/**
 * Source KLASSCI du tableau de bord de l'enseignant : `me/teacher-dashboard`,
 * relayé TEL QUEL avec le jeton du compte. C'est le corps de l'ancienne méthode
 * `ProxyDashboardController::teacherDashboard`, extrait sans changement.
 *
 * Sans jeton, {@see MissingKlassciTokenException} : le rendu des erreurs du proxy
 * en fait le même 401 `klassci_token_missing` qu'avant.
 */
final class KlassciTeacherDashboardReader implements TeacherDashboardReader
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {}

    public function dashboardFor(User $teacher): array
    {
        $klassciToken = KlassciPersonalToken::de($teacher);
        if ($klassciToken === null) {
            throw MissingKlassciTokenException::forUser($teacher->id);
        }

        $this->logger->info('Teacher Dashboard request', [
            'user_id' => $teacher->id,
            'klassci_id' => $teacher->klassci_id,
            'has_klassci_token' => true,
        ]);

        return $this->klassciService->requestWithUserToken($klassciToken, 'me/teacher-dashboard', 'GET');
    }
}
