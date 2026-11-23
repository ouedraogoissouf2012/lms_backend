<?php

declare(strict_types=1);

namespace Tests\Support\SondeMemoire;

/**
 * #1356 — le relevé de la sonde de mémoire (jamais fusionnée).
 *
 * Mesure 2 : la mémoire retenue par classe de test est accompagnée du nombre de
 * classes PHP chargées pendant elle. Le code compilé reste en mémoire du processus,
 * mais il est borné par la taille du dépôt : ce n'est pas une fuite. Une classe de
 * test qui retient de la mémoire SANS charger de code retient des DONNÉES.
 */
final class ReleveMemoire
{
    private const PAS = 250;

    private const TOP = 30;

    private int $tests = 0;

    private int $depart = 0;

    private int $classesAuDepart = 0;

    /** @var list<string> */
    private array $courbe = [];

    private ?string $classe = null;

    private int $avantClasse = 0;

    private int $declareesAvant = 0;

    /** @var array<string, array{0: int, 1: int}> mémoire retenue (octets), classes chargées */
    private array $retenue = [];

    public function depart(): void
    {
        gc_collect_cycles();
        $this->depart = memory_get_usage();
        $this->avantClasse = $this->depart;
        $this->classesAuDepart = $this->declareesAvant = count(get_declared_classes());
    }

    public function testFini(string $classe): void
    {
        if ($classe !== $this->classe) {
            $this->clore();
            $this->classe = $classe;
        }

        $this->tests++;
        if ($this->tests % self::PAS === 0) {
            $this->courbe[] = sprintf('%5d tests : %6.1f Mo, %5d classes chargees', $this->tests, memory_get_usage() / 1048576, count(get_declared_classes()));
        }
    }

    public function rapport(): string
    {
        $this->clore();
        uasort($this->retenue, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        $sansCode = array_filter($this->retenue, static fn (array $r): bool => $r[1] === 0);
        $lignes = [
            '', '=== SONDE MEMOIRE #1356 (mesure 2) ===',
            sprintf('Départ : %.1f Mo, %d classes chargees', $this->depart / 1048576, $this->classesAuDepart),
            '--- Courbe ---', ...$this->courbe,
            sprintf('Fin : %d tests, %.1f Mo, pic %.1f Mo, %d classes chargees', $this->tests, memory_get_usage() / 1048576, memory_get_peak_usage() / 1048576, count(get_declared_classes())),
            sprintf('Retenu total : %.1f Mo sur %d classes de test', array_sum(array_column($this->retenue, 0)) / 1048576, count($this->retenue)),
            sprintf('  dont classes de test qui n ont charge AUCUN code : %.1f Mo sur %d classes (donnees pures)', array_sum(array_column($sansCode, 0)) / 1048576, count($sansCode)),
            sprintf('--- %d classes qui retiennent le plus (Ko retenus, classes chargees) ---', self::TOP),
        ];
        foreach (array_slice($this->retenue, 0, self::TOP, true) as $classe => [$octets, $chargees]) {
            $lignes[] = sprintf('%8.1f Ko %5d  %s', $octets / 1024, $chargees, $classe);
        }
        $lignes[] = sprintf('--- %d classes qui retiennent le plus SANS charger de code ---', self::TOP);
        foreach (array_slice($sansCode, 0, self::TOP, true) as $classe => [$octets]) {
            $lignes[] = sprintf('%8.1f Ko  %s', $octets / 1024, $classe);
        }

        return implode("\n", $lignes)."\n=== FIN SONDE ===\n";
    }

    private function clore(): void
    {
        if ($this->classe === null) {
            return;
        }

        gc_collect_cycles();
        $maintenant = memory_get_usage();
        $declarees = count(get_declared_classes());
        [$octets, $chargees] = $this->retenue[$this->classe] ?? [0, 0];
        $this->retenue[$this->classe] = [$octets + ($maintenant - $this->avantClasse), $chargees + ($declarees - $this->declareesAvant)];
        $this->avantClasse = $maintenant;
        $this->declareesAvant = $declarees;
    }
}
