<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Filet applicatif posé DERRIÈRE le filet base : exécute l'insertion d'une
 * tentative et, si l'index unique la rejette (course concurrente), arbitre le
 * conflit au lieu de laisser remonter un 500 (#540).
 *
 * ## Pourquoi ici, et pas un `lockForUpdate`
 *
 * Le cas réel est « zéro tentative → deux insertions simultanées ». Un
 * `SELECT … FOR UPDATE` ne verrouille aucune ligne quand il n'en retourne
 * aucune : la protection dépendrait alors des *gap locks* du niveau
 * d'isolation, donc du moteur et de sa configuration — et serait totalement
 * inopérante sous SQLite, où la suite de tests tourne. L'index unique, lui, est
 * une garantie du moteur : il protège toute écriture, y compris celles d'un
 * futur import ou d'une commande artisan. Cf. `.claude/specs/540-attempt-quota-race/design.md` §2.
 *
 * ## Contrat d'étanchéité
 *
 * Seule `UniqueConstraintViolationException` est interceptée. Toute autre
 * `QueryException` (colonne absente, FK violée) traverse le guard : la masquer
 * en « conflit métier » transformerait un bug de schéma en 409 silencieux.
 *
 * Classe sans état, injectable par constructeur (§1.6 D) et directement
 * testable en unitaire — elle ne reçoit que des closures.
 *
 * @see AttemptInsertOutcome
 */
final class AttemptConflictGuard
{
    /**
     * Insère une tentative sous le filet d'unicité.
     *
     * @template TAttempt of Model
     *
     * @param  Closure():TAttempt  $insert  Insertion à tenter.
     * @param  Closure():(TAttempt|null)|null  $resolveWinner  Relecture de la
     *         tentative gagnante après une course perdue. `null` (ou un retour
     *         `null`) signifie « rien à récupérer ».
     * @return AttemptInsertOutcome<TAttempt>|null  `null` = course perdue et
     *         aucune tentative à reprendre : l'appelant répond en conflit
     *         métier (409), jamais en 500.
     */
    public function insert(Closure $insert, ?Closure $resolveWinner = null): ?AttemptInsertOutcome
    {
        try {
            return AttemptInsertOutcome::created($insert());
        } catch (UniqueConstraintViolationException) {
            $winner = $resolveWinner === null ? null : $resolveWinner();

            return $winner === null ? null : AttemptInsertOutcome::resolved($winner);
        }
    }
}
