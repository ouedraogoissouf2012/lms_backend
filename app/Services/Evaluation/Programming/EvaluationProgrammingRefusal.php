<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Programming;

/**
 * Un refus de programmation. Sa classe est dans le corps (`reason`, ADR-906-01),
 * jamais dans un en-tête ; `errors` porte les erreurs de champ d'un 422.
 */
final readonly class EvaluationProgrammingRefusal
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public int $statut,
        public string $message,
        public ?string $reason = null,
        public array $errors = [],
    ) {}

    /**
     * Un identifiant de l'autre famille a été envoyé (D132).
     *
     * @param  array<string, mixed>  $donnees
     * @param  list<string>  $champsEtrangers
     */
    public static function horsEspace(array $donnees, array $champsEtrangers): ?self
    {
        foreach ($champsEtrangers as $champ) {
            if (($donnees[$champ] ?? null) !== null) {
                return new self(
                    422,
                    'Cet identifiant n\'appartient pas à l\'espace de votre établissement.',
                    'identifiant_hors_espace',
                );
            }
        }

        return null;
    }
}
