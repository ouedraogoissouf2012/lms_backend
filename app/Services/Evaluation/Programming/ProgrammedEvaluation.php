<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Programming;

/**
 * Ce qu'une source de programmation établit : les identifiants de
 * rattachement (une seule famille, `klassci_*` ou locale) et les libellés.
 *
 * Le service de création y ajoute l'auteur, l'établissement et les champs du
 * formulaire ; ces attributs-ci ne viennent jamais du corps tel quel.
 */
final readonly class ProgrammedEvaluation
{
    /**
     * @param  array<string, int|string|null>  $attributs
     */
    public function __construct(public array $attributs) {}
}
