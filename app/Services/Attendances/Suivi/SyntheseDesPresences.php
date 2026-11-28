<?php

declare(strict_types=1);

namespace App\Services\Attendances\Suivi;

/**
 * La mise en forme de la synthèse des présences (#1683) : les comptes agrégés par élève et par classe, leur taux
 * ({@see TauxDePresence}), et les élèves sous le seuil. Une ligne agrégée par (élève, classe), lue par
 * {@see LigneLue}.
 */
final class SyntheseDesPresences
{
    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array{eleve_id: int, nom: ?string, classe_id: int, presences: int, retards: int, absences_justifiees: int, absences: int, taux: ?int}>
     */
    public static function parEleve(array $lignes): array
    {
        return array_map(static fn (array $l): array => [
            'eleve_id' => LigneLue::entier($l, 'eleve_id'),
            'nom' => LigneLue::texte($l, 'nom'),
            'classe_id' => LigneLue::entier($l, 'classe_id'),
        ] + self::comptes(
            LigneLue::entier($l, 'presences'),
            LigneLue::entier($l, 'retards'),
            LigneLue::entier($l, 'justifiees'),
            LigneLue::entier($l, 'absences'),
        ), $lignes);
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array{classe_id: int, libelle: ?string, presences: int, retards: int, absences_justifiees: int, absences: int, taux: ?int}>
     */
    public static function parClasse(array $lignes): array
    {
        $parClasse = [];
        foreach ($lignes as $l) {
            $id = LigneLue::entier($l, 'classe_id');
            $c = $parClasse[$id] ?? ['libelle' => LigneLue::texte($l, 'classe'), 'p' => 0, 'r' => 0, 'j' => 0, 'a' => 0];
            $c['p'] += LigneLue::entier($l, 'presences');
            $c['r'] += LigneLue::entier($l, 'retards');
            $c['j'] += LigneLue::entier($l, 'justifiees');
            $c['a'] += LigneLue::entier($l, 'absences');
            $parClasse[$id] = $c;
        }
        ksort($parClasse);

        $classes = [];
        foreach ($parClasse as $id => $c) {
            $classes[] = ['classe_id' => $id, 'libelle' => $c['libelle']] + self::comptes($c['p'], $c['r'], $c['j'], $c['a']);
        }

        return $classes;
    }

    /**
     * Les élèves dont un taux mesuré est sous le seuil, chacun une fois, par id.
     *
     * @param  list<array{eleve_id: int, taux: ?int}>  $eleves
     * @return list<int>
     */
    public static function sousLeSeuil(array $eleves, int $seuil): array
    {
        $sous = [];
        foreach ($eleves as $e) {
            if ($e['taux'] !== null && $e['taux'] < $seuil) {
                $sous[$e['eleve_id']] = $e['eleve_id'];
            }
        }
        ksort($sous);

        return array_values($sous);
    }

    /** @return array{presences: int, retards: int, absences_justifiees: int, absences: int, taux: ?int} */
    private static function comptes(int $presences, int $retards, int $justifiees, int $absences): array
    {
        return [
            'presences' => $presences,
            'retards' => $retards,
            'absences_justifiees' => $justifiees,
            'absences' => $absences,
            'taux' => TauxDePresence::de($presences, $absences),
        ];
    }
}
