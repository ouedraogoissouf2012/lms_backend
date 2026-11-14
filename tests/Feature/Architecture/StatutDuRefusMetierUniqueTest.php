<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Garde structurel : la conversion du code d'un refus métier en statut HTTP
 * (« un code de 400 à 599, sinon 422 ») n'est écrite qu'UNE fois, dans
 * `BusinessException::statutHttp()`. Avant, chaque contrôleur en gardait une
 * copie privée, et un contrôleur qui l'oubliait rendait un 500.
 *
 * Forme cherchée dans `app/`, hors commentaires : une comparaison de borne
 * `>= 400` ou `< 600` (ou `<= 599`). Le dénominateur est publié.
 */
final class StatutDuRefusMetierUniqueTest extends TestCase
{
    private const LIEU = 'app/Exceptions/BusinessException.php';

    private const BORNES = '/(?:>=\s*400\b|<\s*600\b|<=\s*599\b)/';

    public function test_la_conversion_du_statut_n_est_ecrite_qu_une_fois(): void
    {
        $copies = [];
        $inspectes = 0;
        $dansLeLieu = 0;

        foreach ($this->fichiersPhp() as $chemin) {
            $inspectes++;
            $trouve = preg_match_all(self::BORNES, $this->codeSansCommentaires($chemin));
            if ($chemin === self::LIEU) {
                $dansLeLieu = (int) $trouve;
            } elseif ($trouve > 0) {
                $copies[] = "{$chemin} : {$trouve}";
            }
        }

        self::assertGreaterThan(100, $inspectes, "seulement {$inspectes} fichier(s) inspecté(s) : le garde ne regarde rien");
        self::assertGreaterThan(0, $dansLeLieu, 'la règle a quitté '.self::LIEU);
        self::assertSame([], $copies, "{$inspectes} fichier(s) inspecté(s) ; copies de la conversion hors de ".self::LIEU." :\n".implode("\n", $copies));
    }

    private function codeSansCommentaires(string $chemin): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(base_path($chemin))) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        return $code;
    }

    /** @return iterable<string> */
    private function fichiersPhp(): iterable
    {
        $racine = base_path('app');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine)) as $fichier) {
            if ($fichier instanceof SplFileInfo && $fichier->isFile() && $fichier->getExtension() === 'php') {
                yield 'app/'.str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
            }
        }
    }
}
