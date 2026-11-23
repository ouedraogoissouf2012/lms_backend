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

    /** @var array<string, int> taille de chaque propriété statique au départ */
    private array $statiquesAuDepart = [];

    public function depart(): void
    {
        gc_collect_cycles();
        $this->depart = $this->avant = memory_get_usage();
        $this->statiquesAuDepart = self::statiques();
    }

    /**
     * Mesure 5 : la taille de chaque propriété statique de chaque classe chargée,
     * en éléments (tableaux comptés récursivement) ou en octets sérialisés.
     *
     * @return array<string, int>
     */
    private static function statiques(): array
    {
        $tailles = [];
        foreach (get_declared_classes() as $classe) {
            if (str_starts_with($classe, 'Tests\Support\SondeMemoire')) {
                continue;
            }
            foreach ((new \ReflectionClass($classe))->getProperties(\ReflectionProperty::IS_STATIC) as $propriete) {
                if (! $propriete->isInitialized()) {
                    continue;
                }
                $valeur = $propriete->getValue();
                try {
                    $taille = is_array($valeur) ? count($valeur, COUNT_RECURSIVE) : (is_object($valeur) ? strlen(serialize($valeur)) : 0);
                } catch (\Throwable) {
                    $taille = is_object($valeur) ? -1 : 0;
                }
                if ($taille !== 0) {
                    $tailles[$classe.'::$'.$propriete->getName()] = $taille;
                }
            }
        }

        return $tailles;
    }

    /** @return list<string> les statiques qui ont le plus grossi depuis le départ */
    private function statiquesQuiGrossissent(): array
    {
        $croissance = [];
        $fin = self::statiques();
        foreach ($fin as $nom => $taille) {
            $delta = $taille - ($this->statiquesAuDepart[$nom] ?? 0);
            if ($delta > 0) {
                $croissance[$nom] = $delta;
            }
        }
        arsort($croissance);

        $lignes = [];
        foreach (array_slice($croissance, 0, 40, true) as $nom => $delta) {
            $lignes[] = sprintf('%10d  (fin %d)  %s', $delta, $fin[$nom], $nom);
        }

        return $lignes;
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
        $lignes[] = '--- 40 proprietes statiques qui ont le plus grossi (elements, ou octets serialises ; -1 = non serialisable) ---';
        array_push($lignes, ...$this->statiquesQuiGrossissent());

        return implode("\n", $lignes)."\n=== FIN SONDE ===\n";
    }

    private function vivantes(): int
    {
        return count(array_filter($this->applications, static fn (WeakReference $r): bool => $r->get() !== null));
    }
}
