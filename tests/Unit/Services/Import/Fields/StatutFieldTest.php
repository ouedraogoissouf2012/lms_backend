<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import\Fields;

use App\Services\Import\Fields\StatutField;
use PHPUnit\Framework\TestCase;

/**
 * #718 — la colonne `statut`, qui décide de la présence réelle en classe.
 *
 * Ce n'est pas une étiquette décorative : `classe_etudiant.statut` filtre
 * `Classe::etudiantsActifs()` et l'audience locale des séances (#712). Écrire
 * « actif » pour un abandon remet un absent dans les listes d'appel.
 *
 * Les valeurs permises sont celles de l'ENUM en base — `actif`, `inactif`,
 * `abandonne` — et rien d'autre : une valeur hors énumération serait refusée
 * par MySQL au milieu de l'exécution asynchrone, donc après écriture partielle.
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

    public function test_une_valeur_hors_enum_est_refusee_avant_l_ecriture(): void
    {
        $issue = $this->field->resolve('suspendu');

        self::assertTrue($issue->isRejected());
        self::assertSame('statut_inconnu', $issue->code);
        self::assertStringContainsStringIgnoringCase('abandonne', (string) $issue->message);
    }
}
