<?php

declare(strict_types=1);

namespace App\Services\Import\Fields;

/**
 * Résout la colonne `date_inscription` d'un fichier d'import (#718).
 *
 * ## L'ordre jour-mois est déclaré, pas deviné
 *
 * `01/02/2026` vaut le 1ᵉʳ février ici et le 2 janvier ailleurs. La cible est
 * francophone : l'ordre jour-mois est posé comme règle (ADR-718-02). Deviner
 * d'après le fichier produirait des dates justes onze mois sur douze et fausses
 * le douzième, ce qui est la pire des deux options.
 *
 * ## Pourquoi `checkdate` et non `createFromFormat`
 *
 * `createFromFormat` accepte `31/02/2026` et le reporte au 3 mars sans rien
 * dire : une date inventée par le parseur, donc crédible, donc jamais relue.
 * La forme est ici reconnue par motif puis validée par {@see checkdate}, qui
 * refuse un jour qui n'existe pas.
 *
 * Classe pure, sans horloge ni dépendance — testable unitairement.
 */
final class DateInscriptionField
{
    /** Fenêtre statique : une inscription hors de là est une faute de frappe. */
    private const MIN_YEAR = 1900;

    private const MAX_YEAR = 2100;

    /** Graphie francophone : jour, puis mois, puis année sur quatre chiffres. */
    private const DAY_FIRST = '/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/';

    /** Graphie ISO, celle qu'un export propre produit. */
    private const ISO = '/^(\d{4})-(\d{1,2})-(\d{1,2})$/';

    public function resolve(string $raw): FieldOutcome
    {
        $declared = trim($raw);

        // Chaîne vide, et non la date du jour : c'est l'exécution qui datera
        // l'inscription qu'elle réalise. Dater une analyse à blanc ferait mentir
        // la colonne si la confirmation arrive trois jours plus tard.
        if ($declared === '') {
            return FieldOutcome::accepted('');
        }

        $parts = $this->split($declared);

        if ($parts === null) {
            return FieldOutcome::rejected(
                'date_invalide',
                sprintf('Date « %s » illisible. Formats acceptés : 31/12/2026 ou 2026-12-31.', $declared),
            );
        }

        [$year, $month, $day] = $parts;

        if (! checkdate($month, $day, $year) || $year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return FieldOutcome::rejected(
                'date_invalide',
                sprintf('Date « %s » inexistante au calendrier.', $declared),
            );
        }

        return FieldOutcome::accepted(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * Les deux motifs sont exclusifs et testés dans cet ordre : seule la graphie
     * ISO commence par quatre chiffres suivis d'un tiret, si bien que
     * `15-09-2026` ne peut pas être lu comme une année 15.
     *
     * @return array{int, int, int}|null année, mois, jour
     */
    private function split(string $declared): ?array
    {
        if (preg_match(self::ISO, $declared, $iso) === 1) {
            return [(int) $iso[1], (int) $iso[2], (int) $iso[3]];
        }

        if (preg_match(self::DAY_FIRST, $declared, $fr) === 1) {
            return [(int) $fr[3], (int) $fr[2], (int) $fr[1]];
        }

        return null;
    }
}
