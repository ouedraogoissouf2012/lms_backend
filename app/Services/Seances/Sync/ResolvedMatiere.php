<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

/**
 * Paire (matière, détails résolus) — résultat de {@see TeacherMatieresResolver::resolve()}.
 *
 * Remplace un shape `array{matiere: array, details: array}` : un typo sur une
 * clé de tableau associatif ne serait détecté qu'au moment de l'usage, loin
 * du point d'erreur réel ; ici, PHPStan/l'IDE le signalent immédiatement.
 * Même philosophie que {@see SeanceSyncStats} (éviter un `array` fragile
 * circulant entre méthodes).
 */
final class ResolvedMatiere
{
    /**
     * @param  array<string, mixed>  $matiere
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly array $matiere,
        public readonly array $details,
    ) {}
}
