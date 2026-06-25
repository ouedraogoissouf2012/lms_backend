<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Matiere;
use Psr\Log\LoggerInterface;

/**
 * MatiereSyncService — issue #258.
 *
 * Synchronise les matières KLASSCI vers la table locale `matieres`. Le modèle
 * {@see Matiere} était conçu pour la sync (klassci_id, klassci_data,
 * last_klassci_sync, isKlassciDataFresh) mais aucun service ne l'alimentait :
 * la table restait vide en prod, ce qui cassait les validations
 * `exists:matieres,id` (Quiz / ForumTopic / UpdateLesson) et l'affichage du
 * libellé de matière dans les dashboards.
 *
 * ## Conception (calquée sur {@see ClasseSyncService})
 *
 * - Upsert idempotent keyé sur `(klassci_id, institution_id)` — le `klassci_id`
 *   n'est PAS unique entre institutions (espaces d'ID KLASSCI indépendants),
 *   cf. unique composite de la migration 2026_06_25_000001.
 * - `institution_id` est passé EXPLICITEMENT par l'appelant : au login le tenant
 *   n'est pas résolu dans `TenantManager` (même raison que `buildCommonData()`
 *   qui fixe `institution_id` à la main). On utilise donc `withoutGlobalScope`.
 * - `klassci_data` stocke la réponse brute ; `last_klassci_sync` horodate.
 * - DI strict (§1.6) : `KlassciProxyService` + `LoggerInterface`, aucune Facade.
 *
 * Non-`final` : collaborateur injecté dans {@see \App\Services\Klassci\Auth\KlassciUserSynchronizer}
 * et doublé dans ses tests unitaires (même convention que {@see KlassciProxyService}).
 *
 * @see app/Models/Matiere.php
 * @see app/Services/ClasseSyncService.php (même pattern)
 */
class MatiereSyncService
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Synchronise les matières accessibles à l'utilisateur (via son token KLASSCI)
     * dans l'institution donnée.
     *
     * @return array{created: int, updated: int, errors: array<int, string>}
     */
    public function syncUserMatieres(string $klassciToken, int $institutionId): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'errors' => []];

        $response = $this->klassciService->requestWithUserToken($klassciToken, 'matieres', 'GET');
        $matieres = is_array($response['data'] ?? null) ? $response['data'] : [];

        foreach ($matieres as $data) {
            if (! is_array($data) || ! isset($data['id'])) {
                continue;
            }

            /** @var array<mixed, mixed> $data */
            $id = $data['id'];

            try {
                $created = $this->syncSingleMatiere($data, $institutionId);
                $created ? $stats['created']++ : $stats['updated']++;
            } catch (\Throwable $e) {
                $this->logger->error('Erreur sync matière KLASSCI', [
                    'klassci_id'     => is_scalar($id) ? $id : null,
                    'institution_id' => $institutionId,
                    'error'          => $e->getMessage(),
                ]);
                $stats['errors'][] = is_scalar($id) ? (string) $id : 'inconnue';
            }
        }

        $this->logger->info('Synchronisation matières terminée', $stats);

        return $stats;
    }

    /**
     * Upsert d'une matière pour une institution. Retourne true si créée.
     *
     * `withoutGlobalScope` + match explicite `(klassci_id, institution_id)` :
     * fonctionne même quand le tenant n'est pas résolu (cas du login).
     *
     * @param  array<mixed, mixed>  $data  Données brutes KLASSCI d'une matière
     */
    private function syncSingleMatiere(array $data, int $institutionId): bool
    {
        $matiere = Matiere::withoutGlobalScope('institution')
            ->where('klassci_id', $data['id'])
            ->where('institution_id', $institutionId)
            ->first();

        $isNew = $matiere === null;
        $matiere ??= new Matiere();

        $matiere->fill([
            'klassci_id'        => $data['id'],
            'institution_id'    => $institutionId,
            'code'              => $data['code'] ?? null,
            'libelle'           => $data['libelle'] ?? $data['name'] ?? $data['nom'] ?? 'Matière sans nom',
            'description'       => $data['description'] ?? null,
            'coefficient'       => $data['coefficient'] ?? 1,
            'credit'            => $data['credit'] ?? 1,
            'filiere_id'        => $this->extractId($data['filiere'] ?? null) ?? $this->scalarId($data['filiere_id'] ?? null),
            'niveau_id'         => $this->extractId($data['niveau'] ?? null) ?? $this->scalarId($data['niveau_id'] ?? null),
            'semestre_id'       => $this->extractId($data['semestre'] ?? null) ?? $this->scalarId($data['semestre_id'] ?? null),
            'klassci_data'      => $data,
            'last_klassci_sync' => now(),
        ]);
        $matiere->save();

        return $isNew;
    }

    /**
     * Extrait un id depuis un champ relationnel pouvant être un objet `{id: ...}`.
     */
    private function extractId(mixed $value): ?int
    {
        if (is_array($value) && isset($value['id']) && is_numeric($value['id'])) {
            return (int) $value['id'];
        }

        return null;
    }

    private function scalarId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
