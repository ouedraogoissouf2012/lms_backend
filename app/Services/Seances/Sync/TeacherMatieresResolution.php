<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

/**
 * Résultat de {@see TeacherMatieresResolver::resolve()} : les matières
 * résolues, ET les IDs de matières demandées au batch mais absentes du
 * résultat (échec HTTP individuel, tolérance partielle de `KlassciBatchFetcher`).
 *
 * Distingue explicitement l'échec d'une matière AVEC un ID exploitable (à
 * compter comme erreur) d'une matière SANS ID exploitable (silencieusement
 * écartée avant même l'appel batch — comportement inchangé, jamais compté
 * comme erreur, ni avant ni après #515).
 */
final class TeacherMatieresResolution
{
    /**
     * @param  array<int, ResolvedMatiere>  $resolved
     * @param  array<int>  $failedMatiereIds
     */
    public function __construct(
        public readonly array $resolved,
        public readonly array $failedMatiereIds,
    ) {}
}
