<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Contracts\MirroredFromKlassci;
use App\Models\Traits\ResolvesMirroredIdentifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Valide qu'un identifiant — LOCAL ou KLASSCI — désigne bien une ligne de
 * l'institution du demandeur.
 *
 * Coquille délibérément vide de logique : toute la règle vit dans
 * {@see ResolvesMirroredIdentifier}, que la couche service
 * interroge de son côté pour TRADUIRE. Valider et résoudre posent ainsi
 * littéralement la même question au même code — la seule façon de garantir
 * qu'une requête acceptée ici ne puisse pas échouer plus loin.
 *
 * ```php
 * 'matiere_id' => [
 *     'nullable',
 *     new PositiveInteger,
 *     new MirroredIdentifierExists(Matiere::class, $user?->institution_id, 'La matière n\'existe pas'),
 * ],
 * ```
 *
 * La forme (entier positif) reste à {@see PositiveInteger} : cette règle-ci ne
 * répond qu'à l'existence, et sur une entrée non numérique elle se contente de
 * ne rien trouver.
 *
 * Vérifié par tests/Feature/Lesson/KlassciIdentifierDualityTest.php.
 */
final class MirroredIdentifierExists implements ValidationRule
{
    /**
     * @param  class-string<MirroredFromKlassci>  $modele  Entité déclarée duale.
     * @param  mixed  $institutionId  Tenant du demandeur ; normalisé par le modèle.
     * @param  string  $message  Message métier, en français, propre au champ.
     */
    public function __construct(
        private readonly string $modele,
        private readonly mixed $institutionId,
        private readonly string $message,
    ) {}

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! ($this->modele)::existsFor($value, $this->institutionId)) {
            $fail($this->message);
        }
    }
}
