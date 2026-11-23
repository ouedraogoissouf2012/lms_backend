<?php

declare(strict_types=1);

namespace Tests\Support\SondeMemoire;

/**
 * #1356 — le relevé de la sonde de mémoire (jamais fusionnée).
 */
final class ReleveMemoire
{
    private const PAS = 250;

    private const TOP = 30;

    private int $tests = 0;

    private int $depart = 0;

    /** @var list<string> */
    private array $courbe = [];

    private ?string $classe = null;

    private int $avantClasse = 0;

    /** @var array<string, int> mémoire retenue par classe, en octets */
    private array $retenue = [];

    public function depart(): void
    {
        gc_collect_cycles();
        $this->depart = memory_get_usage();
        $this->avantClasse = $this->depart;
    }

    public function testFini(string $classe): void
    {
        if ($classe !== $this->classe) {
            $this->clore();
            $this->classe = $classe;
        }

        $this->tests++;
        if ($this->tests % self::PAS === 0) {
            $this->courbe[] = sprintf('%5d tests : %6.1f Mo (pic %6.1f Mo)', $this->tests, memory_get_usage() / 1048576, memory_get_peak_usage() / 1048576);
        }
    }

    public function rapport(): string
    {
        $this->clore();
        arsort($this->retenue);

        $lignes = ['', '=== SONDE MEMOIRE #1356 ===', sprintf('Départ (DataProvider construits) : %.1f Mo', $this->depart / 1048576)];
        array_push($lignes, '--- Courbe ---', ...$this->courbe);
        $lignes[] = sprintf('Fin : %d tests, %.1f Mo, pic %.1f Mo', $this->tests, memory_get_usage() / 1048576, memory_get_peak_usage() / 1048576);
        $lignes[] = sprintf('--- %d classes qui retiennent le plus (total retenu : %.1f Mo, %d classes) ---', self::TOP, array_sum($this->retenue) / 1048576, count($this->retenue));
        foreach (array_slice($this->retenue, 0, self::TOP, true) as $classe => $octets) {
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
        $this->retenue[$this->classe] = ($this->retenue[$this->classe] ?? 0) + ($maintenant - $this->avantClasse);
        $this->avantClasse = $maintenant;
    }
}
