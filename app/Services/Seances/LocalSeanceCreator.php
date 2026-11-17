<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Models\Seance;
use App\Models\User;

/**
 * #710 — création locale. Jamais d'identifiant local dans klassci_*.
 *
 * La source de l'école AUTONOME du port {@see CreationDeSeance} (#1182) : une école
 * KLASSCI crée ses séances dans KLASSCI ({@see KlassciCreationDeSeance}).
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
final class LocalSeanceCreator implements CreationDeSeance
{
    public function __construct(private readonly AttributsDeSeanceLocale $attributs) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws BusinessException si la classe ou la matière n'est pas celle de
     *                           l'établissement (404), ou si la matière n'est
     *                           pas au programme de la classe (422)
     */
    public function create(User $author, array $payload): Seance
    {
        $titre = is_string($payload['titre'] ?? null) ? $payload['titre'] : '';
        $dateSeance = $this->attributs->instant($payload['date_seance'] ?? null);
        $classeId = $this->attributs->classeDeLEtablissement($payload['classe_id'] ?? null, $author->institution_id);
        $matiere = $this->attributs->matiereDeLEtablissement($payload['matiere_id'] ?? null, $author->institution_id);
        $this->attributs->exigerLeCouple($classeId, $matiere?->id);
        $nomLibre = is_string($payload['matiere_nom'] ?? null) ? $payload['matiere_nom'] : null;

        return Seance::query()->create([
            'titre' => $titre,
            'date_seance' => $dateSeance,
            // Avec un id, le libellé de la matière fait foi : le nom libre est ignoré.
            'matiere_id' => $matiere?->id,
            'matiere_nom' => $matiere !== null ? $matiere->libelle : $nomLibre,
            'classe_nom' => is_string($payload['classe_nom'] ?? null) ? $payload['classe_nom'] : null,
            'classe_id' => $classeId,
            'created_by' => $author->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_matiere_id' => null,
            'klassci_classe_id' => null,
            'is_active' => true,
            'institution_id' => $author->institution_id,
        ]);
    }
}
