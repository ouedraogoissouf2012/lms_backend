<?php

declare(strict_types=1);

namespace Tests\Support\SondeMemoire;

use Illuminate\Container\Container;
use WeakReference;

/**
 * #1356 — le relevé de la sonde de mémoire (jamais fusionnée).
 *
 * Mesure 4 :
 * - la mémoire retenue TEST PAR TEST (après `gc_collect_cycles()`), pour savoir si la
 *   rétention est un coût constant par test ou le fait de quelques tests ;
 * - le nombre d'applications Laravel encore vivantes, par références faibles qui ne
 *   les retiennent pas : une application par test est créée, une seule devrait vivre.
 */
final class ReleveMemoire
{
    private const PAS = 500;

    private const TOP = 25;

    private int $tests = 0;

    private int $depart = 0;

    private int $avant = 0;

    /** @var list<string> */
    private array $courbe = [];

    /** @var array<string, int> retenu par test, en octets */
    private array $parTest = [];

    /** @var list<WeakReference<object>> */
    private array $applications = [];

    public function depart(): void
    {
        gc_collect_cycles();
        $this->depart = $this->avant = memory_get_usage();
    }

    /** Après le `setUp` : l'application du test est l'instance courante du conteneur. */
    public function testPret(): void
    {
        $this->applications[] = WeakReference::create(Container::getInstance());
    }

    public function testFini(string $test): void
    {
        gc_collect_cycles();
        $maintenant = memory_get_usage();
        $this->parTest[$test] = $maintenant - $this->avant;
        $this->avant = $maintenant;

        $this->tests++;
        if ($this->tests % self::PAS === 0) {
            $this->courbe[] = sprintf('%5d tests : %6.1f Mo, applications vivantes : %d', $this->tests, $maintenant / 1048576, $this->vivantes());
        }
    }

    public function rapport(): string
    {
        $valeurs = array_values($this->parTest);
        sort($valeurs);
        $n = count($valeurs);
        $positifs = count(array_filter($valeurs, static fn (int $v): bool => $v > 0));
        arsort($this->parTest);

        $lignes = [
            '', '=== SONDE MEMOIRE #1356 (mesure 4) ===',
            sprintf('Départ : %.1f Mo', $this->depart / 1048576),
            '--- Courbe ---', ...$this->courbe,
            sprintf('Fin : %d tests, %.1f Mo, pic %.1f Mo, applications vivantes : %d', $this->tests, memory_get_usage() / 1048576, memory_get_peak_usage() / 1048576, $this->vivantes()),
            sprintf('Retenu par test : mediane %.1f Ko, p90 %.1f Ko, p99 %.1f Ko, moyenne %.1f Ko ; %d tests sur %d retiennent > 0',
                $valeurs[intdiv($n, 2)] / 1024, $valeurs[(int) ($n * 0.9)] / 1024, $valeurs[(int) ($n * 0.99)] / 1024, array_sum($valeurs) / max(1, $n) / 1024, $positifs, $n),
            sprintf('--- %d tests qui retiennent le plus ---', self::TOP),
        ];
        foreach (array_slice($this->parTest, 0, self::TOP, true) as $test => $octets) {
            $lignes[] = sprintf('%8.1f Ko  %s', $octets / 1024, $test);
        }

        return implode("\n", $lignes)."\n=== FIN SONDE ===\n";
    }

    private function vivantes(): int
    {
        return count(array_filter($this->applications, static fn (WeakReference $r): bool => $r->get() !== null));
    }
}
