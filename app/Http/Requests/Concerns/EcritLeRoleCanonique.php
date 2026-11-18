<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\Role;
use App\Rules\AssignableRole;

/**
 * Le rôle reçu s'écrit sous sa valeur canonique (#1246).
 *
 * ## Le défaut que ce concern supprime
 *
 * La gestion accepte un alias de rôle en entrée (`teacher`, `student`, `administrateur`…) :
 * {@see AssignableRole} le reconnaît par {@see Role::tryFromString}. Mais
 * `validated()` rendait la chaîne REÇUE, et l'écrivain la stockait telle quelle. Les
 * lectures qui comparent la valeur canonique (`->where('role', 'etudiant')`) ne voyaient
 * alors pas le compte : ni compté, ni averti.
 *
 * ## Pourquoi `prepareForValidation`
 *
 * Il passe AVANT la création du validateur, dont `validated()` lit les données figées.
 * Un `merge` en `passedValidation` n'atteindrait pas `validated()`. Effet voulu :
 * `AssignableRole` juge la valeur canonique, ce qui ne change rien à son verdict.
 *
 * ## Ce que ce concern refuse de faire
 *
 * - Une valeur non reconnue, ou qui n'est pas une chaîne, est laissée telle quelle :
 *   la validation la refuse (422), comme avant. `Etudiant` n'est pas reconnu (#949).
 * - Sans `role` dans la requête, aucune clé n'est ajoutée : une modification partielle
 *   ne touche pas le rôle.
 *
 * Pas de mutateur sur le modèle : il agirait en silence sur des écrivains qui stockent un
 * alias exprès (des tests de lecture), et `DB::table` le contournerait. La garde
 * `tests/Feature/Architecture/LeRoleSEcritCanoniqueTest.php` tient la frontière.
 */
trait EcritLeRoleCanonique
{
    protected function prepareForValidation(): void
    {
        $recu = $this->input('role');
        $canonique = is_string($recu) ? Role::tryFromString($recu) : null;

        if ($canonique !== null) {
            $this->merge(['role' => $recu]);
        }
    }
}
