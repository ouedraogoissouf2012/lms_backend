<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Autorisation\DroitsDuCompte;
use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * La garde de route par PERMISSION (#1665, lot 1) : `permission:<valeur>` remplace les listes `role:…` et les
 * gardes `gestion` / `personnel`. Le compte passe si {@see DroitsDuCompte} lui accorde la permission.
 *
 * Réponses inchangées (mêmes corps que l'ancienne garde) : 401 sans compte, 403 sinon, sans jamais dire ni la
 * permission exigée ni le rôle du compte. Une valeur inconnue du catalogue ferme la route (403) et se journalise
 * en erreur : c'est une faute de la route, jamais un accès accordé.
 */
final class EnsurePermission
{
    public const ALIAS = 'permission';

    public function __construct(
        private readonly DroitsDuCompte $droits,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next, string $exigee): Response
    {
        $compte = $request->user();
        if (! $compte instanceof User) {
            return response()->json(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        $permission = Permission::tryFrom($exigee);
        if ($permission === null) {
            $this->logger->error('Permission inconnue du catalogue sur une route', ['permission' => $exigee, 'endpoint' => $request->path()]);

            return $this->refus();
        }

        if (! $this->droits->accorde($compte, $permission)) {
            $this->logger->warning('Accès refusé - Permission absente', [
                'user_id' => $compte->id,
                'user_role' => $compte->role,
                'permission' => $permission->value,
                'endpoint' => $request->path(),
            ]);

            return $this->refus();
        }

        return $next($request);
    }

    private function refus(): Response
    {
        return response()->json(['success' => false, 'message' => 'Accès refusé - Permissions insuffisantes'], 403);
    }
}
