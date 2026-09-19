<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import\Fields;

use App\Services\Import\Fields\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * #718 — la dette E.164 de la clé de déduplication, payée pour ce qui peut l'être.
 *
 * ## La dette
 *
 * `normalizePhone()` retirait TOUS les non-chiffres, le `+` compris. Le même
 * abonné écrit `+22670000000` puis `0022670000000` produisait donc deux clés,
 * donc deux comptes. Or #718 fait de l'idempotence sur cette clé la propriété
 * qui « permet à un formateur de corriger trois lignes et de renvoyer tout le
 * fichier » — c'est exactement ce geste qui échouait.
 *
 * ## Ce qui est normalisé, et ce qui ne peut pas l'être
 *
 * E.164 exige un INDICATIF PAYS. Mesuré : le dépôt n'en a aucune source — ni
 * configuration, ni colonne sur `institutions`, `locale` vaut `en` et le fuseau
 * `UTC`. Et le parc est mixte : `esbtp-abidjan` est ivoirien (+225), les jeux
 * d'essai sont burkinabè (+226).
 *
 * Inventer un pays par défaut le rendrait donc FAUX pour une partie du parc, en
 * silence. La règle retenue est plus étroite et honnête :
 *
 *   - les formes INTERNATIONALES sont ramenées à E.164 — `+` et le préfixe
 *     d'accès international `00` désignent sans ambiguïté le même numéro ;
 *   - les formes NATIONALES sont laissées telles quelles, parce qu'on ne sait
 *     pas de quel pays elles viennent.
 *
 * Deviner serait pire que s'abstenir : deux abonnés de pays différents portant
 * le même numéro national seraient fusionnés en un seul compte.
 *
 * @see docs/adr/2026-09-19-718-03-cle-de-deduplication.md
 */
final class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $normaliseur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normaliseur = new PhoneNormalizer;
    }

    public function test_la_dette_de_718_les_deux_ecritures_internationales_donnent_la_meme_cle(): void
    {
        // Le cœur de la dette. Avant, le `+` était retiré et `00` conservé :
        // deux clés, deux comptes, pour un seul abonné.
        self::assertSame(
            $this->normaliseur->normalize('+22670000000'),
            $this->normaliseur->normalize('0022670000000'),
        );
    }

    public function test_une_forme_internationale_sort_en_e164(): void
    {
        self::assertSame('+22670000000', $this->normaliseur->normalize('+22670000000'));
        self::assertSame('+22670000000', $this->normaliseur->normalize('0022670000000'));
    }

    public function test_la_mise_en_forme_d_un_tableur_ne_change_pas_la_cle(): void
    {
        // Ce qu'un tableur sème : espaces, points, tirets, parenthèses.
        foreach (['+226 70 00 00 00', '+226-70-00-00-00', '+226 (70) 00.00.00', ' +22670000000 '] as $ecriture) {
            self::assertSame('+22670000000', $this->normaliseur->normalize($ecriture), "Échoué sur « $ecriture »");
        }
    }

    public function test_un_numero_national_reste_intact_faute_de_pays_connu(): void
    {
        // Le point que l'on refuse de deviner. Sans indicatif, `70000000` peut
        // être ivoirien, burkinabè, ou d'ailleurs. Lui coller un pays par
        // défaut fusionnerait deux abonnés distincts sous une seule clé.
        self::assertSame('70000000', $this->normaliseur->normalize('70000000'));
        self::assertSame('70000000', $this->normaliseur->normalize('70 00 00 00'));
    }

    public function test_un_national_et_un_international_restent_distincts(): void
    {
        // Conséquence assumée de la règle précédente : sans pays, on ne peut pas
        // affirmer que ces deux-là désignent la même personne.
        //
        // Les VALEURS sont assertées, pas seulement leur inégalité. Un
        // `assertNotSame` seul passe pour quantité de comportements faux — la
        // falsification l'a montré : supprimer le `+` laissait ce test vert
        // alors que la forme E.164 avait disparu.
        self::assertSame('70000000', $this->normaliseur->normalize('70000000'));
        self::assertSame('+22670000000', $this->normaliseur->normalize('+22670000000'));
    }

    public function test_une_forme_sans_indicatif_mais_a_rallonge_n_est_pas_devinee(): void
    {
        // `22670000000` ressemble à du burkinabè sans `+`, mais rien ne le dit :
        // ce pourrait être un numéro national commençant par 226. On ne préfixe
        // donc pas de `+` — ce serait exactement deviner.
        self::assertSame('22670000000', $this->normaliseur->normalize('22670000000'));
    }

    public function test_une_cellule_vide_reste_vide(): void
    {
        self::assertSame('', $this->normaliseur->normalize(''));
        self::assertSame('', $this->normaliseur->normalize('   '));
    }

    public function test_un_texte_sans_chiffre_ne_produit_pas_de_cle(): void
    {
        // Sinon une cellule « à demander » deviendrait une clé d'identité, et
        // tous les élèves sans numéro se fondraient en un seul compte.
        self::assertSame('', $this->normaliseur->normalize('a demander'));
        self::assertSame('', $this->normaliseur->normalize('+'));
    }
}
