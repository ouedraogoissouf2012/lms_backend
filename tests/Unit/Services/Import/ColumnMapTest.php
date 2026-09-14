<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Services\Import\ColumnMap;
use PHPUnit\Framework\TestCase;

/**
 * #718 — résolution « champ canonique → valeur de l'enregistrement ».
 *
 * Sans cartographie, l'objet se réduit à l'identité : c'est ce qui évite une
 * condition dans le classement des lignes (cf. ADR-718-01).
 */
final class ColumnMapTest extends TestCase
{
    public function test_sans_cartographie_le_champ_est_cherche_sous_son_propre_nom(): void
    {
        $map = ColumnMap::fromRequest([]);

        self::assertSame('Doe', $map->value(['nom' => 'Doe'], 'nom'));
    }

    public function test_la_cartographie_redirige_vers_l_en_tete_du_fichier(): void
    {
        $map = ColumnMap::fromRequest(['prenom' => 'Prénom']);

        self::assertSame('Awa', $map->value(['prénom' => 'Awa'], 'prenom'));
    }

    public function test_l_en_tete_est_normalise_comme_a_la_lecture_du_fichier(): void
    {
        // Le lecteur passe les en-têtes en minuscules et les débarrasse des
        // espaces ; la cartographie doit subir exactement le même traitement,
        // sinon « PRÉNOM » envoyé par le client ne retrouve jamais sa colonne.
        $map = ColumnMap::fromRequest(['prenom' => '  PRÉNOM  ']);

        self::assertSame('Awa', $map->value(['prénom' => 'Awa'], 'prenom'));
    }

    public function test_l_espace_insecable_est_retiree_comme_le_fait_le_client(): void
    {
        // `trim()` de PHP ne connaît que les blancs ASCII, `trim()` de
        // JavaScript retire aussi l'espace insécable — que les tableurs
        // sèment volontiers en bord de cellule. Sans cette égalisation, le
        // client enverrait « Nom » pour une colonne que le serveur a indexée
        // sous « nom⍽ » : introuvable, donc toutes les lignes refusées.
        self::assertSame('nom', ColumnMap::normalizeHeader("Nom\u{00A0}"));
        self::assertSame('prenom', ColumnMap::normalizeHeader("\u{00A0}Prenom "));
    }

    public function test_une_colonne_absente_de_l_enregistrement_vaut_la_chaine_vide(): void
    {
        $map = ColumnMap::fromRequest(['email' => 'Courriel']);

        self::assertSame('', $map->value(['nom' => 'Doe'], 'email'));
    }

    public function test_les_champs_hors_liste_canonique_sont_ignores(): void
    {
        // Une clé inconnue envoyée par un client ne doit pas pouvoir détourner
        // la lecture d'un champ canonique.
        $map = ColumnMap::fromRequest(['inconnu' => 'X', 'nom' => 'Nom']);

        self::assertSame('Doe', $map->value(['nom' => 'Doe'], 'nom'));
        self::assertSame('', $map->value(['x' => 'y'], 'inconnu'));
    }

    public function test_une_valeur_non_scalaire_vaut_la_chaine_vide(): void
    {
        $map = ColumnMap::fromRequest([]);

        self::assertSame('', $map->value(['nom' => ['tableau']], 'nom'));
    }
}
