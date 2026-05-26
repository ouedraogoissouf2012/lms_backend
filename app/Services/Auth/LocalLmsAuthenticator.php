<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Psr\Log\LoggerInterface;

/**
 * Authentification locale LMS (comptes supradmin + users déjà sync depuis KLASSCI).
 *
 * ## Issue #120 — Extrait de `AuthController::login()` étape 1 (528 lignes → orchestrateur)
 *
 * Logique extraite des lignes 110-141 de l'ancien `AuthController.php`. C'est
 * la première étape du flow login : avant d'aller chercher sur KLASSCI, on
 * tente le login local (pour les supradmin LMS qui n'existent pas dans KLASSCI,
 * et comme fast-path pour les users déjà synchronisés qui ont un password local).
 *
 * ## Recherche cross-institution
 *
 * Utilise `withoutGlobalScope('institution')` car :
 * - Le **supradmin** est cross-institution par nature (n'appartient à aucune
 *   institution spécifique).
 * - Au moment du login, le tenant n'est pas encore résolu — on ne peut pas
 *   scoper par institution.
 *
 * Cette ouverture est sûre car compensée par `Hash::check($password)` qui
 * exige le mot de passe correct du user trouvé.
 *
 * ## DI strict (§1.6 D)
 *
 * - `Hasher` (contract Illuminate) pour `Hash::check()` — pas de Facade
 * - `LoggerInterface` (PSR-3) pour les logs — pas de Facade `Log::`
 *
 * Mockable trivialement en test unit via `Mockery::mock(Hasher::class)`.
 *
 * @see app/Http/Controllers/API/AuthController.php (avant refactor) lignes 110-141
 * @see .claude/specs/auth-controller-refactor/design.md §2.1
 */
class LocalLmsAuthenticator
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Tente une authentification locale par email ou nom.
     *
     * @return User|null  null si user introuvable ou password incorrect
     */
    public function attemptLocalAuth(string $identifier, string $password): ?User
    {
        $user = User::withoutGlobalScope('institution')
            ->where(function ($query) use ($identifier): void {
                $query->where('email', $identifier)
                    ->orWhere('name', $identifier);
            })
            ->first();

        if (!$user instanceof User) {
            return null;
        }

        // Les users créés depuis KLASSCI ont password = Hash::make(uniqid()) — non
        // utilisable. Cette branche check protège contre un login local accidentel
        // sur un user qui devrait passer par KLASSCI.
        if (!is_string($user->password) || $user->password === '') {
            return null;
        }

        if (!$this->hasher->check($password, $user->password)) {
            return null;
        }

        $this->logger->info('Authentification locale LMS réussie', [
            'user_id' => $user->id,
            'role'    => $user->role,
        ]);

        return $user;
    }
}
