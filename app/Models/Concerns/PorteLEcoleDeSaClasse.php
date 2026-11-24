<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Classe;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

/**
 * Une ligne pivot d'une classe appartient à l'école de cette CLASSE, par construction (#801).
 *
 * ## Pourquoi
 *
 * Un pivot ne passe pas par `BelongsToInstitution` : un `attach()` ou un `sync()` sans
 * `institution_id` écrivait la ligne SANS école, même avec un tenant résolu
 * (`LocalClasseMatiereLinker::rattacher()`, à chaque rattachement d'une école autonome).
 * Rendre la colonne obligatoire aurait fait échouer 502 écritures par `attach`/`sync`.
 *
 * ## La règle
 *
 * - Non fournie : l'école de la classe. Lue sur la classe déjà chargée quand l'écriture part
 *   d'elle (`$classe->etudiants()->attach(...)`), sans requête ; sinon une lecture de `classes`.
 * - Fournie et CONTRAIRE à celle de la classe : refusée. Un choix silencieux rangerait la ligne
 *   dans une autre école que sa classe.
 * - Classe sans école : rien n'est dérivé, et la base tranche. Aucun repli sur le tenant, qui
 *   n'est pas l'école de la classe.
 *
 * @mixin Pivot
 */
trait PorteLEcoleDeSaClasse
{
    protected static function bootPorteLEcoleDeSaClasse(): void
    {
        static::creating(static fn (Pivot $ligne) => self::rattacher($ligne));
        // À la mise à jour, seulement quand l'école change ou manque encore : une mise à jour
        // sans rapport (un statut) n'a pas à relire la classe.
        static::updating(static function (Pivot $ligne): void {
            if ($ligne->isDirty('institution_id') || $ligne->getAttribute('institution_id') === null) {
                self::rattacher($ligne);
            }
        });
    }

    private static function rattacher(Pivot $ligne): void
    {
        $ecole = self::ecoleDeLaClasse($ligne);
        if ($ecole === null) {
            return;
        }

        $fournie = $ligne->getAttribute('institution_id');
        if ($fournie === null) {
            // falsification : rien n est derive

            return;
        }

        if (! is_numeric($fournie) || (int) $fournie !== $ecole) {
            throw new LogicException(sprintf(
                'La ligne %s porte l\'école %s, mais sa classe %s appartient à l\'école %d (#801).',
                $ligne->getTable(),
                is_scalar($fournie) ? (string) $fournie : '?',
                self::texte($ligne->getAttribute('classe_id')),
                $ecole,
            ));
        }
    }

    private static function ecoleDeLaClasse(Pivot $ligne): ?int
    {
        $classeId = $ligne->getAttribute('classe_id');
        $parent = $ligne->pivotParent;

        $ecole = $parent instanceof Classe && self::texte($parent->getKey()) === self::texte($classeId)
            ? $parent->institution_id
            : Classe::query()->withoutGlobalScope('institution')->whereKey($classeId)->value('institution_id');

        return is_numeric($ecole) ? (int) $ecole : null;
    }

    private static function texte(mixed $valeur): string
    {
        return is_scalar($valeur) ? (string) $valeur : '?';
    }
}
