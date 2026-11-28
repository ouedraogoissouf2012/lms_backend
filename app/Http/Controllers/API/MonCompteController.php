<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\User\SourceDuCompte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le compte connecté gère LE SIEN (#1667) : son mot de passe, son profil (nom affiché, téléphone).
 *
 * L'ordre compte : la PORTE d'abord (un compte d'école KLASSCI est renvoyé à KLASSCI avec son motif, quel que soit le
 * corps), la validation ensuite. Le courriel est refusé ici : le changer exige une confirmation par lien (autre issue).
 */
final class MonCompteController extends Controller
{
    private const REGLES_MOT_DE_PASSE = [
        'current_password' => ['required', 'string', 'current_password:sanctum'],
        'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
    ];

    private const MESSAGES_MOT_DE_PASSE = [
        'current_password.required' => 'Le mot de passe actuel est obligatoire.',
        'current_password.current_password' => 'Le mot de passe actuel est incorrect.',
        'password.required' => 'Le nouveau mot de passe est obligatoire.',
        'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        'password.confirmed' => 'Les mots de passe ne correspondent pas.',
        'password.different' => "Le nouveau mot de passe doit différer de l'actuel.",
    ];

    private const REGLES_PROFIL = [
        'name' => ['sometimes', 'required', 'string', 'max:255'],
        'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        'email' => ['prohibited'],
    ];

    private const MESSAGES_PROFIL = [
        'name.required' => 'Le nom est obligatoire.',
        'name.max' => 'Le nom compte 255 caractères au plus.',
        'phone.max' => 'Le téléphone compte 40 caractères au plus.',
        'email.prohibited' => 'Le courriel ne se modifie pas ici.',
    ];

    /** PUT /api/auth/me/password */
    public function changerMotDePasse(Request $request, SourceDuCompte $sources): JsonResponse
    {
        $compte = $this->compte($request);
        $source = $sources->motDePasse($compte);

        try {
            $source->ouvert($compte);
            $saisie = $request->validate(self::REGLES_MOT_DE_PASSE, self::MESSAGES_MOT_DE_PASSE);
            // Jeton Bearer uniquement (front cross-origin) : la cle du jeton de la requete, qui survit.
            $cle = $compte->currentAccessToken()->getKey();
            $source->changer(
                $compte,
                is_string($saisie['password'] ?? null) ? $saisie['password'] : '',
                is_int($cle) ? $cle : null,
            );
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(null, 'Mot de passe changé.');
    }

    /** PATCH /api/auth/me */
    public function modifierProfil(Request $request, SourceDuCompte $sources): JsonResponse
    {
        $compte = $this->compte($request);
        $source = $sources->profil($compte);

        try {
            $source->ouvert($compte);
            $saisie = $request->validate(self::REGLES_PROFIL, self::MESSAGES_PROFIL);
            $champs = [];
            if (array_key_exists('name', $saisie) && is_string($saisie['name'])) {
                $champs['name'] = trim($saisie['name']);
            }
            if (array_key_exists('phone', $saisie)) {
                $champs['phone'] = is_string($saisie['phone']) ? trim($saisie['phone']) : null;
            }
            $modifie = $source->modifier($compte, $champs);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(['name' => $modifie->name, 'phone' => $modifie->phone], 'Profil mis à jour.');
    }

    private function compte(Request $request): User
    {
        $compte = $request->user();
        abort_unless($compte instanceof User, 401);

        return $compte;
    }
}
