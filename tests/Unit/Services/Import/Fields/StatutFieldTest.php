<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import\Fields;

use App\Enums\ClasseEtudiantStatut;
use App\Services\Import\Fields\StatutField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #718 — la colonne `statut`, qui décide de la présence réelle en classe.
 *
 * Ce n'est pas une étiquette décorative : `classe_etudiant.statut` filtre
 * `Classe::etudiantsActifs()` et l'audience locale des séances (#712). Écrire
 * « actif » pour un abandon remet un absent dans les listes d'appel.
 *
 * Les statuts sont ceux de `ClasseEtudiantStatut` (ADR-711-02). Un fichier en
 * importe trois : `actif`, `inactif`, `abandonne`. Il en refuse deux, pour une
 * raison MÉTIER, et non plus pour un ENUM MySQL disparu (#920) : `expire`
 * découle d'une date de fin que le fichier ne porte pas, et `suspendu` ne se
 * distingue d'`inactif` par aucune règle écrite.
 */
final class StatutFieldTest extends TestCase
{
    private StatutField $field;

    protected function setUp(): void
    {
        parent::setUp();
        $this->field = new StatutField;
    }

    public function test_une_colonne_absente_inscrit_un_present(): void
    {
        // Le défaut de la colonne en base, et celui du code d'avant.
        $issue = $this->field->resolve('');

        self::assertFalse($issue->isRejected());
        self::assertSame('actif', $issue->value);
    }

    public function test_les_trois_valeurs_de_l_enum_passent(): void
    {
        self::assertSame('actif', $this->field->resolve('actif')->value);
        self::assertSame('inactif', $this->field->resolve('inactif')->value);
        self::assertSame('abandonne', $this->field->resolve('abandonne')->value);
    }

    public function test_la_casse_et_les_blancs_du_tableur_ne_font_pas_echouer(): void
    {
        self::assertSame('actif', $this->field->resolve(' ACTIF ')->value);
    }

    public function test_l_accent_que_tout_francophone_tape_est_accepte(): void
    {
        // La colonne en base s'écrit `abandonne`, sans accent. Personne ne le
        // tapera comme ça dans un tableur : refuser « abandonné » ferait rejeter
        // le mot juste au profit de l'orthographe de la base.
        self::assertSame('abandonne', $this->field->resolve('Abandonné')->value);
    }

    public function test_un_statut_inconnu_est_refuse_avant_l_ecriture(): void
    {
        $issue = $this->field->resolve('diplome');

        self::assertTrue($issue->isRejected());
        self::assertSame('statut_inconnu', $issue->code);
        self::assertStringContainsStringIgnoringCase('abandonne', (string) $issue->message);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonImportables(): array
    {
        return [
            'expire : il découle d\'une date de fin' => ['expire', 'date de fin'],
            'suspendu : aucune règle ne le distingue d\'inactif' => ['suspendu', 'inactif'],
        ];
    }

    /** #920 : le statut est connu, mais un fichier ne le pose pas, et le refus dit pourquoi. */
    #[DataProvider('nonImportables')]
    public function test_un_statut_connu_mais_non_importable_dit_pourquoi(string $statut, string $raison): void
    {
        $issue = $this->field->resolve($statut);

        self::assertTrue($issue->isRejected());
        self::assertSame('statut_non_importable', $issue->code);
        self::assertStringContainsString($raison, (string) $issue->message);
    }

    /**
     * #920 : chaque cas de `ClasseEtudiantStatut` est classé, accepté ou refusé
     * avec sa raison. Un statut nouveau fait rougir ce test : il force une
     * décision, au lieu d'un refus silencieux par « inconnu ».
     */
    public function test_chaque_statut_de_l_enumeration_est_classe(): void
    {
        foreach (ClasseEtudiantStatut::cases() as $cas) {
            $issue = $this->field->resolve($cas->value);

            if ($issue->isRejected()) {
                self::assertSame('statut_non_importable', $issue->code, "« {$cas->value} » est refusé comme inconnu, sans raison.");
            } else {
                self::assertSame($cas->value, $issue->value);
            }
        }
    }
}
