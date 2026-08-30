<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use Tests\TestCase;

/**
 * Garde structurel : le LMS ne doit JAMAIS décider d'archiver une séance en
 * sondant `GET seances/{id}` chez KLASSCI.
 *
 * ## Le défaut que ce test verrouille
 *
 * `SeanceExistenceBatchChecker` (#516, supprimé) interrogeait
 * `{base}/seances/{id}` et traduisait un 404 en `ConfirmedDeleted`, ce qui
 * menait le job `CleanObsoleteSeances` (supprimé) à écrire `is_active = false`.
 *
 * Or cette route N'EXISTE PAS chez KLASSCI : elle répond
 * `404 « The route api/lms/seances/{id} could not be found »` pour TOUT
 * identifiant. Le 404 ne signalait donc pas une séance supprimée mais une route
 * absente — deux causes indiscernables depuis un code HTTP nu. Toute séance
 * vérifiée était classée supprimée, et le job tournant toutes les 30 minutes,
 * l'établissement entier était désactivé en un cycle.
 *
 * Le dépôt portait déjà la contradiction :
 * {@see \App\Services\Seances\Mutations\VisioToggleService} journalise
 * « Workaround: endpoint GET /seances/{id} inexistant dans KLASSCI » pendant que
 * le vérificateur l'appelait.
 *
 * ## Pourquoi les tests d'origine ne l'ont pas vu
 *
 * Ils feignaient `seances/1 => Http::response([...], 200)` — une réponse que
 * KLASSCI ne produit jamais. La feinte encodait la prémisse au lieu de la
 * vérifier. D'où ce garde STRUCTUREL : il porte sur le code source, pas sur un
 * double, et aucune feinte ne peut le satisfaire à tort.
 *
 * ## Le mécanisme correct, déjà en place
 *
 * {@see \App\Services\Seances\Sync\StaleSeanceArchiver} (#582) archive sur
 * `synced_at < début du cycle`, marqué par
 * {@see \App\Services\Seances\Sync\SeanceSyncStamper} depuis les endpoints qui,
 * eux, existent. Il est de surcroît protégé par le garde de souillure de
 * {@see \App\Services\Seances\Sync\TenantArchiveCoordinator}, qui refuse
 * d'archiver un tenant dont le cycle a subi une erreur.
 *
 * @see PRODUCTION_STANDARDS.md §1.3 (tests obligatoires)
 */
final class NoPhantomKlassciSeanceProbeTest extends TestCase
{
    /**
     * Répertoires balayés — tout le code exécutable susceptible d'émettre un
     * appel KLASSCI.
     */
    private const SCANNED_DIRECTORIES = ['app', 'routes'];

    public function test_no_source_file_builds_a_klassci_seances_by_id_url(): void
    {
        $offenders = [];

        foreach ($this->phpSourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            // Ce qui distingue un appel KLASSCI d'un lien profond frontend
            // (`"/seances/{$id}"`, légitime) : une URL de BASE interpolée ou
            // concaténée AVANT le segment. On exige donc une variable des deux
            // côtés — `"{$baseUrl}/seances/{$id}"` ou `$url.'/seances/'.$id`.
            $klassciUrlBuild = '#(?:\$\w+\}/seances/\{\$|\$\w+\s*\.\s*[\'"]/seances/)#';

            if (preg_match($klassciUrlBuild, $source) === 1) {
                $offenders[] = $this->relative($file);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "L'endpoint KLASSCI `seances/{id}` n'existe pas : il répond 404 pour tout "
            ."identifiant. Décider d'un archivage sur ce 404 désactive l'établissement "
            .'entier. Utiliser StaleSeanceArchiver (synced_at) à la place. '
            .'Fichiers fautifs : '.implode(', ', $offenders),
        );
    }

    /**
     * Inventaire FIGÉ des sites qui désactivent une séance.
     *
     * Deux seulement, sur deux critères disjoints :
     *   - {@see \App\Services\Seances\Sync\StaleSeanceArchiver} — non confirmée
     *     par KLASSCI durant le cycle (`synced_at`), sous garde de souillure ;
     *   - {@see \App\Jobs\ArchiveOldSeances} — ancienneté.
     *
     * Tout TROISIÈME site fait échouer ce test, délibérément. C'est ainsi que
     * `CleanObsoleteSeances` avait pu coexister : un archiveur de plus, sur un
     * critère faux, que rien n'obligeait à se déclarer. Un archivage de masse
     * doit être une décision explicite, jamais une addition silencieuse.
     */
    public function test_seance_deactivation_sites_are_exactly_the_two_known_archivers(): void
    {
        $expected = [
            'app/Jobs/ArchiveOldSeances.php',
            'app/Services/Seances/Sync/StaleSeanceArchiver.php',
        ];

        $writers = [];

        foreach ($this->phpSourceFiles() as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));

            if (preg_match("#'is_active'\s*=>\s*false#", $code) === 1) {
                $writers[] = $this->relative($file);
            }
        }

        sort($writers);

        self::assertSame(
            $expected,
            $writers,
            'Un site de désactivation de séance a été ajouté ou retiré. '
            .'Chaque archiveur supplémentaire est un chemin de destruction de masse : '
            .'le déclarer ici est obligatoire, avec son critère et sa garde.',
        );
    }

    /**
     * Retire commentaires et docblocks : un critère d'archivage CITÉ dans une
     * explication ne doit pas compter comme un site d'archivage.
     */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @return iterable<string>
     */
    private function phpSourceFiles(): iterable
    {
        foreach (self::SCANNED_DIRECTORIES as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    yield $file->getPathname();
                }
            }
        }
    }

    private function relative(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(base_path()) + 1));
    }
}
