<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use Database\Factories\SeanceFactory;

/**
 * Frappe les identifiants KLASSCI des fixtures, hors de portée des tests (#682).
 *
 * ## Le défaut corrigé
 *
 * Les factories tiraient ces identifiants **au hasard, dans les plages que les
 * tests utilisent aussi à la main** :
 *
 * ```php
 * 'klassci_id'        => fake()->unique()->numberBetween(1, 10000),   // User, Classe, Matiere
 * 'klassci_seance_id' => fake()->unique()->numberBetween(1000, 9999), // Seance
 * ```
 *
 * `unique()` ne garantit l'unicité qu'entre les valeurs **qu'il génère
 * lui-même**. Il ignore tout des identifiants écrits en dur par les tests. Dès
 * qu'un fichier mélange les deux dans la même institution, la collision devient
 * une question de probabilité — et la colonne portant un index unique, elle se
 * manifeste par un échec de contrainte.
 *
 * ## Pourquoi cela paraissait aléatoire
 *
 * Faker part d'une graine déterministe pour toute l'exécution : **ajouter ou
 * retirer un test n'importe où décale la séquence entière** et peut faire tomber
 * un tirage sur une valeur codée en dur. Le défaut est donc invisible tant qu'on
 * ne touche à rien, et mord sans prévenir sur une PR sans rapport — constaté le
 * 2026-09-03, sur une seule jambe CI, pour un même commit.
 *
 * ## La correction
 *
 * Une **séquence monotone** par espace d'identifiants, partant d'un plancher
 * très au-dessus de tout ce que les tests écrivent à la main (le maximum relevé
 * dans `tests/` est `200002`). Deux propriétés en découlent :
 *
 * 1. **Aucune collision avec une valeur codée en dur** — les plages ne se
 *    recoupent plus du tout, aujourd'hui comme pour les tests écrits demain.
 * 2. **Aucun appariement fortuit entre espaces** — les planchers sont disjoints,
 *    donc le `klassci_enseignant_id` d'une séance ne peut pas tomber par hasard
 *    sur le `klassci_id` d'un utilisateur. Sans index unique, une telle
 *    coïncidence ne lèverait aucune erreur : elle ferait **passer** une
 *    assertion d'autorisation qui aurait dû échouer. Un lien voulu reste
 *    évidemment possible — il s'écrit explicitement, comme dans
 *    {@see SeanceFactory::forTeacher()}.
 *
 * La séquence est statique et ne redescend jamais au sein d'un processus : deux
 * tests successifs ne se voient donc jamais attribuer le même identifiant, même
 * si la base est réinitialisée entre eux.
 */
trait MintsKlassciIdentifiers
{
    /**
     * Un plancher par espace d'identifiants, séparés par 10 millions.
     *
     * L'écart est délibérément énorme : il n'a aucun coût (la colonne est un
     * `unsignedBigInteger`) et rend impossible qu'une suite, si longue soit-elle,
     * fasse déborder un espace dans le suivant.
     */
    private const KLASSCI_ID_FLOORS = [
        'user' => 910_000_000,
        'classe' => 920_000_000,
        'matiere' => 930_000_000,
        'seance' => 940_000_000,
        'enseignant' => 950_000_000,
        'evaluation' => 960_000_000,
        'etudiant' => 970_000_000,
    ];

    /** @var array<string, int> compteur courant de chaque espace */
    private static array $klassciSequences = [];

    /**
     * Rend un identifiant neuf pour cet espace, jamais rendu auparavant.
     *
     * @param  string  $space  une clé de {@see self::KLASSCI_ID_FLOORS}
     */
    protected function mintKlassciId(string $space): int
    {
        // Échec bruyant plutôt que repli sur un plancher par défaut : une faute
        // de frappe sur l'espace donnerait sinon deux factories partageant
        // silencieusement la même séquence, et rétablirait l'appariement fortuit
        // que ce collaborateur existe pour supprimer.
        if (! array_key_exists($space, self::KLASSCI_ID_FLOORS)) {
            throw new \InvalidArgumentException("Espace d'identifiants KLASSCI inconnu : {$space}");
        }

        $rang = (self::$klassciSequences[$space] ?? 0) + 1;
        self::$klassciSequences[$space] = $rang;

        return self::KLASSCI_ID_FLOORS[$space] + $rang;
    }
}
