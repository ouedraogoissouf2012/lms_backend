<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;

/**
 * Résout en un seul appel batch les détails des matières d'un enseignant.
 *
 * ## Issue #515 — élimine le N+1 HTTP
 *
 * Extrait de `KlassciSeancesSyncService` pour garder le fichier sous la limite
 * §1.1 (≤300 lignes) : cette classe a une seule responsabilité (résoudre les
 * détails de matières en batch) et ne dépend que de `KlassciProxyService` —
 * aucun couplage avec la logique d'upsert de séance.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300 lignes) · §1.6 D (DI strict)
 */
final class TeacherMatieresResolver
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * Récupère les détails de chaque matière listée, via UN SEUL appel batch
     * (pool HTTP parallèle) plutôt que N appels séquentiels.
     *
     * Les matières sans ID exploitable sont écartées avant l'appel batch
     * (comportement inchangé, jamais compté comme erreur). Les matières AVEC
     * un ID exploitable mais absentes du résultat batch (échec HTTP individuel
     * — tolérance partielle de `KlassciBatchFetcher`) sont distinguées via
     * `failedMatiereIds`, pour que l'appelant puisse restaurer le comptage
     * `stats->errors` qu'assurait l'ancien code séquentiel (#515 — sinon un
     * échec de fetch redevient invisible côté supervision).
     *
     * @param  array<int, array<string, mixed>>  $matieresList
     */
    public function resolve(array $matieresList, string $teacherToken): TeacherMatieresResolution
    {
        $matieresById = KlassciPayload::keyById(
            $matieresList,
            fn (array $matiere): ?int => KlassciPayload::toInt($matiere['id'] ?? null),
        );
        if ($matieresById === []) {
            return new TeacherMatieresResolution([], []);
        }

        $detailsById = $this->klassciService->fetchManyMatieresDetails(array_keys($matieresById), $teacherToken);

        $resolved = [];
        foreach ($detailsById as $matiereId => $details) {
            $resolved[$matiereId] = new ResolvedMatiere($matieresById[$matiereId], $details);
        }

        $failedMatiereIds = array_values(array_diff(array_keys($matieresById), array_keys($detailsById)));

        return new TeacherMatieresResolution($resolved, $failedMatiereIds);
    }
}
