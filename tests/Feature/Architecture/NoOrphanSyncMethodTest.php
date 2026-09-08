<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Garde structurel : une méthode de synchronisation sans appelant est du code
 * mort, et le pire genre — elle se teste très bien.
 *
 * ## Le défaut que ce garde verrouille
 *
 * `ClasseSyncService::syncUserClasses()` n'était appelée par PERSONNE. Ses
 * seules mentions dans le dépôt étaient des `@see` de docblocks.
 *
 * Le lot #740 y avait branché l'amorçage du miroir `classe_matiere`, avec un
 * test dédié — qui appelait la méthode DIRECTEMENT. Le test prouvait donc que
 * la méthode FONCTIONNE ; il ne prouvait pas qu'elle est ATTEINTE. Mesure en
 * production le 2026-09-08 : `classe_matiere` à 0 ligne, et « Mes Classes »
 * vide malgré un correctif déployé et vert en CI.
 *
 * C'est un faux vert d'un genre particulier : aucun test ne rougit, aucune
 * analyse statique ne bronche, et seule la production dit la vérité.
 *
 * ## Ce que le garde vérifie
 *
 * Toute méthode publique `sync*` d'un service doit être appelée quelque part
 * dans `app/` ou `routes/`, en dehors de la classe qui la déclare.
 *
 * Mesure à l'écriture : 9 méthodes, 1 seule orpheline — celle du défaut. Le
 * garde est donc précis, pas bruyant.
 *
 * ## Ce qu'il ne vérifie PAS
 *
 * Qu'un appelant soit lui-même atteignable. La chaîne complète relève des
 * tests de bout en bout ; ce garde attrape le maillon manquant le plus
 * grossier, celui qui ne coûte rien à détecter.
 */
final class NoOrphanSyncMethodTest extends TestCase
{
    /**
     * Méthodes tolérées, chacune justifiée. Cette liste ne doit pas s'allonger.
     *
     * @var list<string>
     */
    private const TOLEREES = [];

    public function test_no_public_sync_method_is_left_without_a_caller(): void
    {
        $orphelines = array_values(array_diff($this->orphanSyncMethods(), self::TOLEREES));

        self::assertSame(
            [],
            $orphelines,
            "Ces méthodes de synchronisation ne sont appelées nulle part :\n  - "
            .implode("\n  - ", $orphelines)
            ."\n\nUne synchro sans appelant ne tourne jamais en production, même si"
            ." ses tests sont verts :\nils l'appellent directement. Branche-la sur un"
            .' chemin réel, ou supprime-la.'
        );
    }

    /**
     * @return list<string>
     */
    private function orphanSyncMethods(): array
    {
        $declarations = [];

        foreach ($this->phpFiles(app_path('Services')) as $chemin) {
            $source = (string) file_get_contents($chemin);
            preg_match_all('/public function (sync[A-Za-z0-9_]*)\s*\(/', $source, $trouvees);

            foreach ($trouvees[1] as $methode) {
                $declarations[] = [$this->relative($chemin), $methode];
            }
        }

        $corpus = [];
        foreach ([app_path(), base_path('routes')] as $racine) {
            foreach ($this->phpFiles($racine) as $chemin) {
                $corpus[$this->relative($chemin)] = (string) file_get_contents($chemin);
            }
        }

        $orphelines = [];
        foreach ($declarations as [$fichier, $methode]) {
            if (! $this->calledOutside($methode, $fichier, $corpus)) {
                $orphelines[] = $fichier.'::'.$methode;
            }
        }

        sort($orphelines);

        return $orphelines;
    }

    /**
     * @param  array<string, string>  $corpus
     */
    private function calledOutside(string $methode, string $declarant, array $corpus): bool
    {
        $motif = '/->'.preg_quote($methode, '/').'\s*\(/';

        foreach ($corpus as $fichier => $source) {
            if ($fichier !== $declarant && preg_match($motif, $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $racine): array
    {
        if (! is_dir($racine)) {
            return [];
        }

        $fichiers = [];

        /** @var iterable<\SplFileInfo> $iterateur */
        $iterateur = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine)),
            '/\.php$/'
        );

        foreach ($iterateur as $fichier) {
            $fichiers[] = $fichier->getPathname();
        }

        return $fichiers;
    }

    private function relative(string $chemin): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($chemin, strlen(base_path()) + 1));
    }
}
