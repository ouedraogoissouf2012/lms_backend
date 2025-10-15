<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\KlassciProxyService;
use Illuminate\Support\Facades\Log;

/**
 * Middleware EnsureKlassciSync
 *
 * S'assure que les données utilisateur KLASSCI sont à jour (< 24h)
 * Si les données sont obsolètes, déclenche une re-synchronisation automatique
 */
class EnsureKlassciSync
{
    public function __construct(
        private KlassciProxyService $klassciService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Vérifier si l'utilisateur est authentifié
        $user = $request->user();

        if (!$user) {
            // Pas d'utilisateur authentifié, laisser passer
            // (le middleware auth:sanctum se chargera de bloquer)
            return $next($request);
        }

        // Vérifier si les données KLASSCI sont fraîches
        if ($user->isKlassciDataFresh()) {
            // Données à jour, continuer
            return $next($request);
        }

        // Les données sont obsolètes, re-synchroniser
        Log::info("Re-synchronisation KLASSCI pour user {$user->id}", [
            'user_id' => $user->id,
            'last_sync' => $user->last_klassci_sync?->toIso8601String(),
        ]);

        try {
            // Appeler l'API KLASSCI pour récupérer les données actuelles
            $klassciMe = $this->klassciService->get('auth/me');

            if (isset($klassciMe['data']['user'])) {
                $klassciUser = $klassciMe['data']['user'];

                // Mettre à jour les données utilisateur
                $user->update([
                    'name' => $klassciUser['nom'] ?? $klassciUser['name'] ?? $user->name,
                    'email' => $klassciUser['email'] ?? $user->email,
                    'role' => $klassciUser['role'] ?? $user->role,
                    'klassci_data' => json_encode($klassciUser),
                    'last_klassci_sync' => now(),
                ]);

                Log::info("Re-synchronisation KLASSCI réussie pour user {$user->id}");
            }

        } catch (\Exception $e) {
            // En cas d'erreur, logger mais continuer quand même
            // Les données locales (même obsolètes) restent utilisables
            Log::warning("Échec re-synchronisation KLASSCI pour user {$user->id}", [
                'error' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }
}
