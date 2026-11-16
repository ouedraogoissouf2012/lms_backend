<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Garde structurelle de #910 : le droit d'écrire le catalogue local n'est lu
 * qu'à UN endroit.
 *
 * Avant #910, cinq services écrivaient à la main « l'établissement tient-il son
 * catalogue ? puis est-il résolu ? ». Ils divergeaient sur l'ordre, sur le
 * message et sur le motif du 409. Une sixième copie divergerait à son tour :
 * hors de la porte, aucun appel à `->allowsLocalCatalogue(` n'est accepté.
 *
 * Les deux implémentations DÉCLARENT la méthode (`function allowsLocalCatalogue(`) :
 * le motif cherché est l'APPEL, elles ne sont donc pas signalées.
 */
final class SeulePorteDuCatalogueLocalTest extends TestCase
{
    private const PORTE = 'app/Services/Catalogue/PorteDuCatalogueLocal.php';

    public function test_seule_la_porte_lit_le_droit_du_catalogue_local(): void
    {
        $inspectes = 0;
        $appelants = [];

        foreach ($this->phpFiles(app_path()) as $chemin) {
            $relatif = $this->relative($chemin);
            $inspectes++;

            if ($relatif !== self::PORTE
                && str_contains((string) file_get_contents($chemin), '->allowsLocalCatalogue(')) {
                $appelants[] = $relatif;
            }
        }

        self::assertGreaterThan(100, $inspectes, 'La garde n\'a presque rien inspecté : elle ne prouverait rien.');

        self::assertSame(
            [],
            $appelants,
            'Le droit du catalogue local est lu hors de sa porte ('.self::PORTE.") :\n  - "
            .implode("\n  - ", $appelants)
            ."\n\nAppelle PorteDuCatalogueLocal::etablissement()."
        );
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $racine): array
    {
        $fichiers = [];
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterateur as $fichier) {
            if ($fichier instanceof \SplFileInfo && $fichier->getExtension() === 'php') {
                $fichiers[] = $fichier->getPathname();
            }
        }

        sort($fichiers);

        return $fichiers;
    }

    private function relative(string $chemin): string
    {
        return ltrim(str_replace([base_path(), '\\'], ['', '/'], $chemin), '/');
    }
}
