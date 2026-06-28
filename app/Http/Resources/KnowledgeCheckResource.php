<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Présentation API d'un `KnowledgeCheck`.
 *
 * ## Sécurité (fuite de triche)
 *
 * Le `questions` (JSON) d'un quiz contient `correct_answer` (et souvent
 * `explanation`). Les endpoints de lecture CRUD — `index`, `show`,
 * `getByChapter` — sont en `auth:sanctum` **sans restriction de rôle** : un
 * étudiant pouvait donc lire les bonnes réponses AVANT/PENDANT sa tentative.
 *
 * Cette Resource **masque `correct_answer` et `explanation` pour tout
 * non-staff** (étudiant) ; le **staff** (`isStaff()` : enseignant / coordinateur
 * / admin) garde la vue complète pour éditer/relire. Le flux Attempt
 * (start/submit) gère sa propre logique de révélation post-soumission.
 *
 * Tous les autres champs sont préservés à l'identique (`parent::toArray`) :
 * zéro changement de contrat hors le masquage voulu.
 *
 * @see app/Http/Controllers/API/KnowledgeCheckCrudController.php
 */
final class KnowledgeCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = parent::toArray($request);

        $user = $request->user();
        if ($user === null || ! $user->isStaff()) {
            $data['questions'] = self::maskAnswers($data['questions'] ?? []);
        }

        return $data;
    }

    /**
     * Retire `correct_answer` et `explanation` de chaque question.
     *
     * @param  mixed  $questions
     * @return array<int, mixed>
     */
    private static function maskAnswers($questions): array
    {
        if (! is_array($questions)) {
            return [];
        }

        return array_map(static function ($question) {
            if (is_array($question)) {
                unset($question['correct_answer'], $question['explanation']);
            }

            return $question;
        }, array_values($questions));
    }
}
