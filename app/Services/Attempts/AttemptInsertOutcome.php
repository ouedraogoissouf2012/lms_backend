<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use Illuminate\Database\Eloquent\Model;

/**
 * Issue d'une insertion de tentative passée sous le filet d'unicité de la base
 * ({@see AttemptConflictGuard}).
 *
 * Deux états, et deux seulement :
 *
 * | Fabrique     | `isResolved()` | Sens métier                                       |
 * |--------------|----------------|---------------------------------------------------|
 * | `created()`  | `false`        | insertion gagnante, rien à arbitrer               |
 * | `resolved()` | `true`         | course perdue, mais la gagnante a été récupérée   |
 *
 * Le troisième cas — course perdue **et** rien à récupérer — n'est pas un état
 * de cet objet : le guard renvoie alors `null`. Cela rend l'absence de
 * tentative impossible à confondre avec une tentative présente, et supprime du
 * type toute possibilité de `attempt()` nul chez l'appelant.
 *
 * Constructeur privé : les états incohérents sont inconstructibles.
 *
 * @template TAttempt of Model
 */
final class AttemptInsertOutcome
{
    /** @var TAttempt */
    private readonly Model $attempt;

    /**
     * @param  TAttempt  $attempt
     */
    private function __construct(Model $attempt, private readonly bool $conflicted)
    {
        $this->attempt = $attempt;
    }

    /**
     * Insertion réussie du premier coup.
     *
     * @template TCreated of Model
     *
     * @param  TCreated  $attempt
     * @return self<TCreated>
     */
    public static function created(Model $attempt): self
    {
        return new self($attempt, false);
    }

    /**
     * Course perdue, mais la tentative gagnante a pu être relue : l'appelant
     * peut la renvoyer telle quelle (cas du double-clic).
     *
     * @template TResolved of Model
     *
     * @param  TResolved  $attempt
     * @return self<TResolved>
     */
    public static function resolved(Model $attempt): self
    {
        return new self($attempt, true);
    }

    /**
     * @return TAttempt
     */
    public function attempt(): Model
    {
        return $this->attempt;
    }

    /**
     * Le conflit a-t-il été arbitré au profit d'une tentative existante ?
     * `true` signifie « l'index unique a rejeté notre insertion, voici la
     * gagnante » — l'appelant présente alors une reprise, pas une création.
     */
    public function isResolved(): bool
    {
        return $this->conflicted;
    }
}
