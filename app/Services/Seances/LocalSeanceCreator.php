<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Seance;
use App\Models\User;

/**
 * #710 — création locale. Jamais d'identifiant local dans klassci_*.
 *
 * ## La classe locale (#846)
 *
 * Jusqu'ici une séance locale ne gardait qu'un `classe_nom`, du texte libre.
 * Elle n'avait donc **aucun lien** vers une classe, et `canRead:25` court-
 * circuitait la porte étudiant : tout apprenant d'une école autonome était privé
 * des enregistrements de ses propres cours, sauf s'il avait été présent.
 *
 * `classe_id` est **facultatif** : une séance peut se créer avant que la classe
 * soit arrêtée, et le champ libre reste utile pour l'affichage. Mais s'il est
 * fourni, la classe doit être de l'établissement.
 *
 * @see docs/adr/2026-09-19-846-01-seance-locale-et-sa-classe.md
 */
final class LocalSeanceCreator
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws BusinessException si la classe n'est pas celle de l'établissement
     */
    public function create(User $author, array $payload): Seance
    {
        $titre = is_string($payload['titre'] ?? null) ? $payload['titre'] : '';
        $dateSeance = is_string($payload['date_seance'] ?? null) ? $payload['date_seance'] : '';

        return Seance::query()->create([
            'titre' => $titre,
            'date_seance' => $dateSeance,
            'matiere_nom' => is_string($payload['matiere_nom'] ?? null) ? $payload['matiere_nom'] : null,
            'classe_nom' => is_string($payload['classe_nom'] ?? null) ? $payload['classe_nom'] : null,
            'classe_id' => $this->classeDeLEtablissement($payload, $author),
            'created_by' => $author->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_matiere_id' => null,
            'klassci_classe_id' => null,
            'is_active' => true,
            'institution_id' => $author->institution_id,
        ]);
    }

    /**
     * La classe est bornée à l'établissement de l'auteur, explicitement.
     *
     * `withoutGlobalScopes` parce que le scope multi-tenant est fail-OPEN : s'y
     * fier serait dépendre d'une garde qui se tait quand aucun tenant n'est
     * résolu — et une séance créée dans un job n'en a pas.
     *
     * Le message ne distingue pas « n'existe pas » de « appartient à une autre
     * école » : les séparer dirait à un établissement quels identifiants
     * existent chez ses voisins.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BusinessException
     */
    private function classeDeLEtablissement(array $payload, User $author): ?int
    {
        $classeId = $payload['classe_id'] ?? null;

        if (! is_numeric($classeId)) {
            return null;
        }

        $existe = Classe::query()->withoutGlobalScopes()
            ->where('id', (int) $classeId)
            ->where('institution_id', $author->institution_id)
            ->exists();

        if (! $existe) {
            throw new BusinessException('Classe introuvable dans cet établissement.', 404);
        }

        return (int) $classeId;
    }
}
