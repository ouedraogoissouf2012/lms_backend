<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Chapter;
use App\Models\User;

/**
 * Issue #598 — autorisation du téléchargement du **document source** d'un
 * chapitre.
 *
 * ## Pourquoi ne pas réutiliser {@see ChecksFileAuthorization}
 *
 * L'issue le suggérait, mais le code l'interdit : ses deux méthodes sont typées
 * sur `App\Models\File` (`canReadFile(?File, ?User)`), un modèle sans rapport
 * avec `Chapter` — pas de `is_public`, pas de `user_id`. On écrit donc la règle
 * propre au domaine chapitre, dans le **même esprit** que le trait frère
 * (défense en profondeur, court-circuit ordonné, `supradmin` en minuscules
 * strictes).
 *
 * ## Règle
 *
 * Le document source est un artefact plus fort que le cours rendu : fichier
 * éditable de l'enseignant, susceptible de porter métadonnées, notes ou versions
 * non publiées.
 *
 * ⚠️ **Ce que cette règle NE fait PAS** (relevé par l'audit `spec-security`) :
 * `allow_download` vaut `true` par défaut (`Chapter::$attributes`) et aucune
 * vérification d'inscription n'existe. En pratique, tout compte authentifié de
 * l'institution peut donc télécharger la source de n'importe quel chapitre du
 * tenant — même famille que la fuite inter-classes #482. Ce n'est **pas une
 * régression** : `GET /api/chapters/{id}` ne vérifie rien de plus
 * (`ChapterCrudService::show()` = `findOrFail` + modèle brut). Mais il ne faut
 * pas lire ce trait comme une protection par classe ou par inscription : il
 * n'en est pas une. → dette tracée en issue de suivi.
 *
 * Ordre de court-circuit :
 *
 *   1. `null` (utilisateur ou chapitre absent) → refus ;
 *   2. `supradmin` → autorisé (gestionnaire de plateforme, cross-tenant) ;
 *   3. institution différente → refus (cloisonnement multi-tenant) ;
 *   4. enseignant propriétaire du chapitre → autorisé ;
 *   5. administrateur intra-tenant → autorisé ;
 *   6. sinon → autorisé **seulement si** le chapitre déclare `allow_download`.
 *
 * L'étape 6 s'appuie sur un champ **déjà modélisé** (`Chapter::$fillable`, cast
 * `boolean`, défaut `true`) plutôt que d'inventer une règle produit : un
 * enseignant qui décoche « téléchargement autorisé » voit son intention
 * respectée côté API, pas seulement côté interface.
 *
 * @see PRODUCTION_STANDARDS.md §1.2 Sécurité Absolue
 * @see app/Http/Requests/Concerns/ChecksFileAuthorization.php (trait frère)
 */
trait ChecksChapterDownloadAuthorization
{
    protected function canDownloadChapterOriginal(?Chapter $chapter, ?User $user): bool
    {
        if ($chapter === null || $user === null) {
            return false;
        }

        // Intentionnel : `'supradmin'` en minuscules strictes — l'enum `Role`
        // normaliserait aussi `'superAdmin'` (admin INTRA-tenant) et briserait la
        // distinction délibérée (cf. #102 et le trait frère).
        if ($user->role === 'supradmin') {
            return true;
        }

        if ($chapter->institution_id !== $user->institution_id) {
            return false;
        }

        if ($chapter->enseignant_id === $user->id) {
            return true;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $chapter->allow_download === true;
    }
}
