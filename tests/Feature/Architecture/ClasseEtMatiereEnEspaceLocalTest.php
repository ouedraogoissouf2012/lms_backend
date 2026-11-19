<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * #873 — une classe et une matière se désignent par leur id LOCAL. Le double espace
 * (`localIdFor`, `existsFor` : « le local d'abord, sinon le numéro KLASSCI ») ne vaut plus
 * que pour les séances, le temps de la pile #1154.
 *
 * Rouge si `app/` appelle `localIdFor(` ou `existsFor(` sur autre chose que `Seance`, y
 * compris par un nom de classe dynamique (`$modele::localIdFor`). `localIdForKlassciId`, qui
 * lit un numéro dont l'espace KLASSCI est CONNU (un payload KLASSCI), reste permis.
 */
final class ClasseEtMatiereEnEspaceLocalTest extends TestCase
{
    /** Le mécanisme lui-même, et son contrat. */
    private const LE_MECANISME = [
        'app/Models/Traits/ResolvesMirroredIdentifier.php',
        'app/Models/Contracts/MirroredFromKlassci.php',
    ];

    private const SEUL_DOUBLE_ESPACE = 'Seance';

    public function test_seule_la_seance_se_resout_dans_les_deux_espaces(): void
    {
        $inspectes = 0;
        $sites = [];

        foreach ($this->fichiersPhp() as $relatif => $jetons) {
            $inspectes++;
            if (in_array($relatif, self::LE_MECANISME, true)) {
                continue;
            }
            foreach ($jetons as $i => $jeton) {
                $methode = $this->texte($jetons[$i + 1] ?? null);
                if (! is_array($jeton) || $jeton[0] !== T_DOUBLE_COLON || ! in_array($methode, ['localIdFor', 'existsFor'], true)) {
                    continue;
                }
                $cible = $this->texte($jetons[$i - 1] ?? null);
                if ($cible !== self::SEUL_DOUBLE_ESPACE) {
                    $sites[] = "{$relatif}:{$jeton[2]}  {$cible}::{$methode}";
                }
            }
        }

        self::assertGreaterThan(100, $inspectes, "seulement {$inspectes} fichier(s) inspecté(s) : la garde ne regarde rien");
        self::assertSame([], $sites, "Une classe ou une matière se lit encore dans les deux espaces (#873) :\n".implode("\n", $sites));
    }

    private function texte(mixed $jeton): ?string
    {
        return is_array($jeton) ? $jeton[1] : (is_string($jeton) ? $jeton : null);
    }

    /** @return iterable<string, list<string|array{0: int, 1: string, 2: int}>> chemin relatif => jetons utiles */
    private function fichiersPhp(): iterable
    {
        $racine = base_path('app');
        $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $fichier */
        foreach ($fichiers as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }

            $relatif = 'app/'.str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
            $jetons = array_values(array_filter(
                token_get_all((string) file_get_contents($fichier->getPathname())),
                static fn (mixed $jeton): bool => ! (is_array($jeton) && in_array($jeton[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
            ));

            yield $relatif => $jetons;
        }
    }
}
