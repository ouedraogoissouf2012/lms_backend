<?php

declare(strict_types=1);

namespace App\Services\Klassci\Concerns;

/**
 * Catalogue des 15 méthodes endpoint sémantiques KLASSCI.
 *
 * ## Pourquoi un trait
 *
 * Audit `spec-architect` PR 2 MEDIUM-1 : `KlassciProxyService` dépassait §1.1
 * (300 lignes) à cause des 15 méthodes endpoint (`getStructure`, `getMatieres`,
 * etc.) qui sont des wrappers thin (`get` + endpoint + TTL).
 *
 * Plusieurs solutions ont été évaluées :
 *
 * - **Classe séparée `KlassciEndpointCatalog`** : crée un cycle DI
 *   (`KlassciProxyService` → `EndpointCatalog` → `RawClient` = `KlassciProxyService`).
 *   Ou duplique la logique cache+memo (anti-pattern).
 * - **Suppression des wrappers, callers utilisent `get('structure', [], 3600)`** :
 *   break-change massif sur 12+ controllers, perte de la sémantique métier (TTL
 *   par défaut sensé, signature typée).
 * - **Trait** (cette solution) : extrait le code dans un fichier dédié sans
 *   créer de cycle, sans casser les callers. Les 15 méthodes restent visibles
 *   sur `KlassciProxyService` (back-compat 100%), mais leur code vit ici.
 *
 * ## Contrat
 *
 * Ce trait s'attend à être utilisé dans une classe qui expose les méthodes
 * publiques `get(string, array, ?int): array` et `post(string, array): array`
 * et `put(string, array): array` avec les mêmes signatures que
 * {@see \App\Services\KlassciProxyService::get()}.
 *
 * @see \App\Services\KlassciProxyService
 * @see .claude/specs/perf-02-klassci-batch-cache/design.md §7 (table TTL)
 */
trait HasKlassciEndpointShortcuts
{
    /**
     * @return array<string, mixed>
     */
    public function getStructure(): array
    {
        return $this->get('structure', [], 3600);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getClasses(array $filters = []): array
    {
        return $this->get('classes', $filters, 600);
    }

    /**
     * @return array<string, mixed>
     */
    public function getClasseEtudiants(int $classeId, ?int $anneeId = null): array
    {
        $params = $anneeId ? ['annee_id' => $anneeId] : [];

        return $this->get("classes/{$classeId}/etudiants", $params, 300);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getMatieres(array $filters = []): array
    {
        return $this->get('matieres', $filters, 600);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMatiereDetails(int $id): array
    {
        return $this->get("matieres/{$id}", [], 600);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEnseignants(): array
    {
        return $this->get('enseignants', [], 3600);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEnseignantsEnrichis(bool $withDetails = true): array
    {
        $params = $withDetails ? ['with_details' => 'true'] : [];

        return $this->get('enseignants', $params, 600);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilieres(): array
    {
        return $this->get('filieres', [], 3600);
    }

    /**
     * @return array<string, mixed>
     */
    public function getNiveauxEtudes(): array
    {
        return $this->get('niveaux-etudes', [], 3600);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getEvaluations(array $filters = []): array
    {
        return $this->get('evaluations', $filters, 300);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getEmploiTemps(array $filters = []): array
    {
        return $this->get('emploi-temps', $filters, 600);
    }

    /**
     * @param  array<int, array<string, mixed>>  $notes
     * @return array<string, mixed>
     */
    public function saveNotes(int $evaluationId, array $notes): array
    {
        return $this->post("evaluations/{$evaluationId}/notes", [
            'notes' => $notes,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $presences
     * @return array<string, mixed>
     */
    public function savePresences(int $coursId, array $presences): array
    {
        return $this->post("cours/{$coursId}/presences", [
            'presences' => $presences,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateCoursStatut(int $coursId, string $statut, ?string $commentaire = null): array
    {
        return $this->put("cours/{$coursId}/statut", [
            'statut'      => $statut,
            'commentaire' => $commentaire,
        ]);
    }

    public function testConnection(): bool
    {
        try {
            $response = $this->get('structure');

            return isset($response['success']) && (bool) $response['success'];
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    abstract public function get(string $endpoint, array $params = [], ?int $customTTL = null): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    abstract public function post(string $endpoint, array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    abstract public function put(string $endpoint, array $data): array;
}
