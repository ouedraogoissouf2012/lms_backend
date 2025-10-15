<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\KlassciProxyService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Controller d'authentification
 *
 * Gère l'authentification via l'API KLASSCI et génère des tokens Sanctum locaux
 */
class AuthController extends Controller
{
    public function __construct(
        private KlassciProxyService $klassciService
    ) {}

    /**
     * POST /api/auth/login
     * Connexion utilisateur (proxy vers KLASSCI + création token local)
     */
    public function login(Request $request): JsonResponse
    {
        try {
            // Validation des données
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Données invalides',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Appeler l'API KLASSCI pour authentification
            $klassciResponse = $this->klassciService->post('auth/login', [
                'email' => $request->email,
                'password' => $request->password,
            ]);

            // Vérifier la réponse KLASSCI
            if (!isset($klassciResponse['success']) || !$klassciResponse['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiants incorrects',
                ], 401);
            }

            $klassciUser = $klassciResponse['data']['user'];
            $klassciToken = $klassciResponse['data']['token'];

            // Synchroniser ou créer l'utilisateur local
            $user = $this->syncUserFromKlassci($klassciUser, $klassciToken);

            // Générer un token Sanctum local
            $token = $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Connexion réussie',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'klassci_id' => $user->klassci_id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
                'meta' => [
                    'klassci_synced' => true,
                    'annee_universitaire_courante' => $klassciResponse['meta']['annee_universitaire_courante'] ?? null,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/auth/me
     * Récupère le profil de l'utilisateur connecté
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non authentifié',
                ], 401);
            }

            // Récupérer les données complètes depuis KLASSCI si besoin
            try {
                $klassciMe = $this->klassciService->get('auth/me');
                $userData = $klassciMe['data']['user'] ?? [];
            } catch (\Exception $e) {
                // Si erreur KLASSCI, retourner les données locales
                $userData = [];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'klassci_id' => $user->klassci_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'klassci_data' => $userData,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur récupération profil: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/auth/logout
     * Déconnexion utilisateur (révoque le token actuel)
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Révoquer le token Sanctum actuel
            $request->user()->currentAccessToken()->delete();

            // Optionnel : appeler logout KLASSCI
            try {
                $this->klassciService->post('auth/logout', []);
            } catch (\Exception $e) {
                // Ignorer l'erreur KLASSCI, le logout local est prioritaire
            }

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion réussie',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/auth/refresh
     * Rafraîchit le token utilisateur
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Révoquer l'ancien token
            $request->user()->currentAccessToken()->delete();

            // Créer un nouveau token
            $newToken = $user->createToken('lms-backend-token', ['lms:access'])->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token rafraîchi',
                'data' => [
                    'token' => $newToken,
                    'token_type' => 'Bearer',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur refresh token: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/auth/check
     * Vérifie la validité du token
     */
    public function check(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return response()->json([
                'success' => true,
                'authenticated' => $user !== null,
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ] : null,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'authenticated' => false,
            ]);
        }
    }

    /**
     * Synchronise un utilisateur KLASSCI avec la base locale
     *
     * @param array $klassciUser Données utilisateur de KLASSCI
     * @param string $klassciToken Token KLASSCI
     * @return User
     */
    private function syncUserFromKlassci(array $klassciUser, string $klassciToken): User
    {
        $klassciId = $klassciUser['id'];

        // Chercher l'utilisateur par klassci_id
        $user = User::where('klassci_id', $klassciId)->first();

        $userData = [
            'klassci_id' => $klassciId,
            'name' => $klassciUser['nom'] ?? $klassciUser['name'] ?? 'User',
            'email' => $klassciUser['email'],
            'role' => $klassciUser['role'] ?? 'student',
            'klassci_token' => $klassciToken,
            'klassci_data' => json_encode($klassciUser),
            'last_klassci_sync' => now(),
        ];

        if ($user) {
            // Mettre à jour l'utilisateur existant
            $user->update($userData);
        } else {
            // Créer un nouvel utilisateur
            // Note: password est null car on utilise uniquement KLASSCI pour auth
            $userData['password'] = Hash::make(uniqid()); // Password aléatoire, non utilisé
            $user = User::create($userData);
        }

        return $user;
    }
}
