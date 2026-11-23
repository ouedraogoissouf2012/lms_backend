<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Enums\Role;
use App\Exceptions\KlassciRoleInconnuException;
use App\Http\Middleware\EnsureSchoolMemberOrPlatformRoute;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Présenter des réponses JSON pour les endpoints d'authentification.
 *
 * ## Issue #120 — Extrait de `AuthController` (audit `spec-architect` C4)
 *
 * Avant ce presenter, `AuthController` mélangeait orchestration métier ET
 * construction de réponses JSON avec ~30 champs chacune. Le controller faisait
 * 304 lignes (violant §5 « Controllers ≤ 200 »). L'extraction des 3 méthodes
 * `buildLocalLoginResponse`/`buildKlassciLoginResponse`/`invalidCredentialsResponse`
 * dans ce presenter ramène le controller sous le seuil §5.
 *
 * ## Trois identifiants du compte, chacun dans son espace (#1409)
 *
 * `id` (local), `klassci_id` (numéro de COMPTE KLASSCI) et `klassci_enseignant_id`
 * (identité d'ENSEIGNANT KLASSCI, celle des listes d'enseignants et de l'enseignant
 * d'une évaluation, #873). Le front reconnaît « moi » dans une liste par l'id de SON
 * espace, sans repli croisé. Les trois réponses qui décrivent le compte (connexion
 * locale, connexion KLASSCI, `/auth/me`) les portent toutes, nuls s'ils manquent.
 *
 * ## Pattern Presenter (standard Laravel)
 *
 * Présentation = construction de la response JSON à partir de l'état métier
 * résolu par le controller. Pas de logique de décision, pas d'I/O DB, pas de
 * Facade.
 *
 * @see app/Http/Controllers/API/AuthController.php
 * @see .claude/specs/auth-controller-refactor/design.md §6 (single solution)
 */
class AuthResponsePresenter
{
    /**
     * Réponse de succès pour un login local (sans tenant KLASSCI).
     *
     * `meta.institution` porte le slug de l'école, la valeur que la voie KLASSCI
     * y met déjà ; `meta.institution_name` son nom, lu sur la fiche de l'école
     * (#795). Sans école (supradmin), les deux clés restent présentes, nulles.
     *
     * @param  Institution|null  $institution  L'école du compte, chargée par
     *                                         l'appelant : aucune I/O ici.
     * @param  bool  $peutInscrireLocalement  Capacite resolue par l'appelant.
     *                                        Le presentateur ne decide rien
     *                                        (cf. docblock de classe).
     * @param  bool  $peutGererCatalogueLocal  Idem, la capacite catalogue (#1184).
     */
    public function successfulLocal(
        User $user,
        string $sanctumToken,
        ?Institution $institution,
        bool $peutInscrireLocalement = false,
        bool $peutGererCatalogueLocal = false,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'klassci_id' => $user->klassci_id,
                    'klassci_enseignant_id' => $user->klassci_enseignant_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'token' => $sanctumToken,
                'token_type' => 'Bearer',
            ],
            'meta' => [
                'klassci_synced' => false,
                'is_supradmin' => $user->asRoleEnum() === Role::Supradmin,
                'institution' => $institution?->slug,
                'institution_name' => $institution?->name,
                // Une CAPACITE, jamais le mode : le client demande ce qu'il a
                // le droit de faire, il n'a pas a connaitre la nature de
                // l'etablissement (#805).
                'peut_inscrire_localement' => $peutInscrireLocalement,
                // #1184 : la capacite CATALOGUE (classes, matieres, sessions de
                // formation), distincte de l'inscription des apprenants.
                'peut_gerer_catalogue_local' => $peutGererCatalogueLocal,
            ],
        ]);
    }

    /**
     * Réponse de succès pour un login KLASSCI (avec user sync).
     *
     * @param  array<string, mixed>  $klassciUser  Payload `data.user` de KLASSCI
     * @param  array<string, mixed>  $klassciMeta  Payload `meta` de KLASSCI
     * @param  array{code: string, api_base_url: string}  $tenant
     */
    public function successfulKlassci(
        User $localUser,
        string $sanctumToken,
        array $klassciUser,
        array $klassciMeta,
        array $tenant,
        bool $peutInscrireLocalement = false,
        bool $peutGererCatalogueLocal = false,
    ): JsonResponse {
        $adminData = is_array($klassciUser['admin_data'] ?? null) ? $klassciUser['admin_data'] : null;
        $institutionName = is_string($adminData['etablissement'] ?? null)
            ? $adminData['etablissement']
            : $tenant['code'];

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'data' => [
                'user' => [
                    'id' => $localUser->id,
                    'klassci_id' => $localUser->klassci_id,
                    'name' => $localUser->name,
                    'email' => $localUser->email,
                    'role' => $localUser->role,
                    'role_display_name' => $klassciUser['role_display_name'] ?? '',
                    'avatar' => $klassciUser['avatar'] ?? null,
                    // #504 : `is_admin` / `permissions` / `admin_data` BRUTS retirés de la
                    // réponse de login (défense en profondeur §1.2). Un KLASSCI compromis
                    // pouvait y pousser `is_admin=true`/`permissions=['*']` — y compris
                    // imbriqués dans `admin_data`. Aligne login sur /auth/me + le stockage
                    // (whitelist #477). L'autorité reste `role` ; `institution_name` (dérivé
                    // d'`admin_data.etablissement`) reste exposé en `meta` ci-dessous.
                    'enseignant_data' => $klassciUser['enseignant_data'] ?? null,
                    'etudiant_data' => $klassciUser['etudiant_data'] ?? null,
                ],
                'token' => $sanctumToken,
                'token_type' => 'Bearer',
            ],
            'meta' => [
                'klassci_synced' => true,
                'institution' => $tenant['code'],
                'institution_name' => $institutionName,
                'annee_universitaire_courante' => $klassciMeta['annee_universitaire_courante'] ?? null,
                'peut_inscrire_localement' => $peutInscrireLocalement,
                'peut_gerer_catalogue_local' => $peutGererCatalogueLocal,
            ],
        ]);
    }

    public function invalidCredentials(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Identifiants incorrects',
        ], 401);
    }

    public function loginError(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la connexion.',
        ], 500);
    }

    /**
     * Conflit de compte : l'email confirmé par KLASSCI est déjà détenu par un
     * autre compte de l'institution (anomalie de données). 409 (pas 500) +
     * message orientant vers le support — la résolution est une action admin.
     */
    public function accountConflict(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Conflit de compte détecté : votre adresse e-mail est déjà associée à un autre compte. Contactez l\'administrateur.',
        ], 409);
    }

    /**
     * #1181 — l'établissement du tenant KLASSCI est introuvable ou inactif au
     * moment de synchroniser : aucun compte n'est créé. Le motif du compte sans
     * établissement (ADR-906-01).
     */
    public function etablissementKlassciIntrouvable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "Votre établissement n'est pas disponible. Contactez son administration.",
            'reason' => EnsureSchoolMemberOrPlatformRoute::REASON,
        ], 403);
    }

    /**
     * #949 — KLASSCI désigne, pour un compte nouveau, un rôle que le LMS ne
     * connaît pas : aucun compte n'est créé. 403 motivé (ADR-906-01) : la
     * correction est administrative, dans KLASSCI.
     */
    public function roleKlassciInconnu(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "Votre rôle KLASSCI n'est pas reconnu par le LMS. Contactez l'administration de votre établissement.",
            'reason' => KlassciRoleInconnuException::REASON,
        ], 403);
    }

    /**
     * #243 — Service d'authentification externe (KLASSCI) injoignable.
     * 503 (et non 500/401) : panne temporaire d'un service tiers, le client
     * doit comprendre que ce n'est pas ses identifiants et qu'il peut réessayer.
     */
    public function authServiceUnavailable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Service d\'authentification temporairement indisponible. Veuillez réessayer dans quelques instants.',
        ], 503);
    }

    /**
     * @param  array<string, mixed>  $klassciData
     */
    public function profile(User $user, array $klassciData): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'klassci_id' => $user->klassci_id,
                'klassci_enseignant_id' => $user->klassci_enseignant_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'klassci_data' => $klassciData,
            ],
        ]);
    }

    public function profileError(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la récupération du profil.',
        ], 500);
    }

    public function logoutSuccess(): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Déconnexion réussie']);
    }

    public function logoutError(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la déconnexion.',
        ], 500);
    }

    public function tokenRefreshed(string $newToken): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Token rafraîchi',
            'data' => ['token' => $newToken, 'token_type' => 'Bearer'],
        ]);
    }

    public function refreshError(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors du renouvellement du token.',
        ], 500);
    }

    public function checkResult(?User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'authenticated' => $user !== null,
            'user' => $user !== null ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ] : null,
        ]);
    }

    public function checkUnauthenticated(): JsonResponse
    {
        return response()->json(['success' => false, 'authenticated' => false]);
    }
}
