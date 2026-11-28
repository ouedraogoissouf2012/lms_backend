<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Exceptions\BusinessException;
use App\Exceptions\ConflitDHoraireException;
use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\Conflits\ConflitsDHoraire;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

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
 * ## Type, fin et conflits d'horaire (#1668)
 *
 * Le type et la fin sont facultatifs : sans fin, aucune n'est écrite. L'auteur est
 * l'enseignant de la séance (`created_by`) : un chevauchement avec ses séances ou celles
 * de la classe est refusé ({@see ConflitsDHoraire}), sous verrou, dans la transaction de
 * l'écriture.
 *
 * @see docs/adr/2026-09-19-846-01-seance-locale-et-sa-classe.md
 */
final class LocalSeanceCreator implements CreationDeSeance
{
    public function __construct(
        private readonly AttributsDeSeanceLocale $attributs,
        private readonly ConflitsDHoraire $conflits,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws BusinessException si la classe ou la matière n'est pas celle de
     *                           l'établissement (404), si la matière n'est pas au
     *                           programme de la classe (422), ou si un enseignant
     *                           force un conflit (403)
     * @throws ConflitDHoraireException si le créneau chevauche une séance (409)
     */
    public function create(User $author, array $payload): Seance
    {
        $classeId = $this->attributs->classeDeLEtablissement($payload['classe_id'] ?? null, $author->institution_id);
        $debut = $this->attributs->instant($payload['date_seance'] ?? null);
        $finRecue = $payload['fin_seance'] ?? null;
        $fin = is_string($finRecue) && $finRecue !== '' ? $this->attributs->instant($finRecue) : null;
        $attributs = $this->attributsDeLaSeance($author, $payload, $classeId, $debut, $fin);
        // La porte de l'API refuse un compte sans établissement : l'entier est celui de l'école.
        $creneau = $this->conflits->creneau(
            (int) $author->institution_id,
            CarbonImmutable::parse($debut),
            $fin !== null ? CarbonImmutable::parse($fin) : null,
            $author,
            $classeId,
        );
        // La règle `boolean` admet true, 1 et "1" : les trois forcent.
        $forcer = filter_var($payload['forcer_conflit'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $seance = $this->db->transaction(function () use ($creneau, $author, $forcer, $attributs): Seance {
            $this->conflits->verrouiller($creneau);
            $this->conflits->exigerAucun($creneau, $author, $forcer);

            return Seance::query()->create($attributs);
        });
        if (! $seance instanceof Seance) {
            throw new RuntimeException('Création de la séance interrompue.');
        }

        return $seance;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws BusinessException
     */
    private function attributsDeLaSeance(User $author, array $payload, ?int $classeId, string $debut, ?string $fin): array
    {
        $matiere = $this->attributs->matiereDeLEtablissement($payload['matiere_id'] ?? null, $author->institution_id);
        $this->attributs->exigerLeCouple($classeId, $matiere?->id);
        $nomLibre = is_string($payload['matiere_nom'] ?? null) ? $payload['matiere_nom'] : null;

        return [
            'titre' => is_string($payload['titre'] ?? null) ? $payload['titre'] : '',
            'date_seance' => $debut,
            'fin_seance' => $fin,
            'type_seance' => $payload['type_seance'] ?? null,
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
        ];
    }
}
