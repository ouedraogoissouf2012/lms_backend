<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import\Fields;

use App\Services\Import\Fields\DateInscriptionField;
use PHPUnit\Framework\TestCase;

/**
 * #718 — la colonne `date_inscription`.
 *
 * La cible est francophone : un tableur y écrit `15/09/2026`, jamais
 * `09/15/2026`. L'ordre jour-mois est donc DÉCLARÉ, pas deviné (ADR-718-02).
 *
 * `createFromFormat` seul ne suffirait pas : PHP accepte `31/02/2026` et le
 * reporte silencieusement au 3 mars. Une date inventée par le parseur serait
 * pire que l'absence de date, puisqu'elle serait crédible.
 */
final class DateInscriptionFieldTest extends TestCase
{
    private DateInscriptionField $field;

    protected function setUp(): void
    {
        parent::setUp();
        $this->field = new DateInscriptionField;
    }

    public function test_une_colonne_absente_ne_declare_aucune_date(): void
    {
        // Chaîne vide, et non la date du jour : c'est l'exécution qui datera
        // l'inscription qu'elle réalise. Dater ici une analyse à blanc ferait
        // mentir la colonne si la confirmation arrive trois jours plus tard.
        $issue = $this->field->resolve('');

        self::assertFalse($issue->isRejected());
        self::assertSame('', $issue->value);
    }

    public function test_le_format_francophone_est_lu_jour_d_abord(): void
    {
        self::assertSame('2026-09-15', $this->field->resolve('15/09/2026')->value);
    }

    public function test_le_format_iso_est_accepte_tel_quel(): void
    {
        self::assertSame('2026-09-15', $this->field->resolve('2026-09-15')->value);
    }

    public function test_le_tiret_francophone_est_accepte(): void
    {
        self::assertSame('2026-09-15', $this->field->resolve('15-09-2026')->value);
    }

    public function test_les_blancs_du_tableur_ne_font_pas_echouer(): void
    {
        self::assertSame('2026-09-15', $this->field->resolve('  15/09/2026 ')->value);
    }

    public function test_un_jour_inexistant_est_refuse_et_non_reporte(): void
    {
        $issue = $this->field->resolve('31/02/2026');

        self::assertTrue($issue->isRejected());
        self::assertSame('date_invalide', $issue->code);
    }

    public function test_une_annee_aberrante_est_refusee(): void
    {
        // Frappe courante : deux chiffres perdus. `createFromFormat` l'accepte
        // et rend l'an 226, qu'aucune relecture humaine ne rattraperait.
        $issue = $this->field->resolve('15/09/226');

        self::assertTrue($issue->isRejected());
        self::assertSame('date_invalide', $issue->code);
    }

    public function test_un_texte_libre_est_refuse(): void
    {
        self::assertTrue($this->field->resolve('rentrée')->isRejected());
    }
}
