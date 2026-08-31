<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Visio\Recording;

use App\Services\Visio\Recording\LocalDirectoryRecordingMediaSource;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

/**
 * #469 — localisation du média produit par Jibri.
 *
 * ## Ce que cette classe garde
 *
 * Elle reçoit un identifiant de session **venu du réseau** (le corps du webhook)
 * et le transforme en chemin de fichier. C'est donc la frontière exacte où une
 * traversée de répertoire entrerait : `../../../etc/passwd` concaténé à une
 * racine donnerait un chemin lisible, et le job d'import importerait
 * consciencieusement ce fichier dans un cours.
 *
 * L'invariant est donc : **le client nomme une session, jamais un chemin.** La
 * validation du format précède toute concaténation — pas après, pas « en même
 * temps ».
 *
 * ## Pourquoi refuser plutôt que choisir, quand il y a plusieurs médias
 *
 * Jibri écrit un `.mp4` par session. Zéro fichier ou plusieurs signale un état
 * que personne n'a prévu. Prendre « le premier » attacherait potentiellement le
 * mauvais enregistrement à un cours ; renvoyer `null` fait échouer l'import avec
 * un motif lisible, et laisse le fichier intact pour qu'un opérateur tranche.
 *
 * @see PRODUCTION_STANDARDS.md §1.2 (sécurité absolue) · §1.6 (DI stricte)
 */
final class LocalDirectoryRecordingMediaSourceTest extends TestCase
{
    private const SESSION = '00e7571b-7204-4ecb-8cab-7fb84b57b916';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/jibri-media-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/'.self::SESSION, 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    private function source(?string $root = null): LocalDirectoryRecordingMediaSource
    {
        return new LocalDirectoryRecordingMediaSource(
            new Filesystem,
            $root ?? $this->root,
        );
    }

    private function writeMedia(string $name): string
    {
        $path = $this->root.'/'.self::SESSION.'/'.$name;
        file_put_contents($path, 'octets');

        return $path;
    }

    // ------------------------------------------------------------ chemin nominal

    public function test_locates_the_single_media_of_a_session(): void
    {
        $expected = $this->writeMedia('salle_2026-08-31-12-20-35.mp4');

        $this->assertSame($expected, $this->source()->locate(self::SESSION));
    }

    public function test_ignores_files_that_are_not_media(): void
    {
        $expected = $this->writeMedia('salle.mp4');
        $this->writeMedia('metadata.json');

        $this->assertSame($expected, $this->source()->locate(self::SESSION));
    }

    // ------------------------------------------------------- absences et ambiguïtés

    public function test_returns_null_when_the_session_directory_holds_no_media(): void
    {
        $this->writeMedia('metadata.json');

        $this->assertNull($this->source()->locate(self::SESSION));
    }

    public function test_returns_null_when_the_session_directory_is_unknown(): void
    {
        $this->assertNull($this->source()->locate('11111111-2222-3333-4444-555555555555'));
    }

    /**
     * Deux médias : on refuse au lieu d'en choisir un. Attacher le mauvais
     * enregistrement à un cours est pire qu'un échec explicite.
     */
    public function test_returns_null_when_several_media_are_present(): void
    {
        $this->writeMedia('a.mp4');
        $this->writeMedia('b.mp4');

        $this->assertNull($this->source()->locate(self::SESSION));
    }

    public function test_returns_null_when_the_root_is_not_configured(): void
    {
        $this->writeMedia('salle.mp4');

        $this->assertNull($this->source('')->locate(self::SESSION));
    }

    // ------------------------------------------------------------------ traversée

    /**
     * @return list<array{string}>
     */
    public static function hostileIdentifiers(): array
    {
        return [
            'traversée relative' => ['../../../etc'],
            'traversée encodée' => ['..%2f..%2fetc'],
            'chemin absolu POSIX' => ['/etc/passwd'],
            'chemin absolu Windows' => ['C:\\Windows\\System32'],
            'séparateur injecté' => [self::SESSION.'/../'.self::SESSION],
            'octet nul' => ["00e7571b-7204-4ecb-8cab-7fb84b57b916\0.mp4"],
            'majuscules hors format' => ['00E7571B-7204-4ECB-8CAB-7FB84B57B916'],
            'vide' => [''],
            'joker' => ['*'],
        ];
    }

    /**
     * @dataProvider hostileIdentifiers
     */
    public function test_rejects_hostile_identifiers(string $identifier): void
    {
        $this->assertNull($this->source()->locate($identifier));
    }

    /**
     * Le refus doit intervenir AVANT toute concaténation : un identifiant
     * malformé ne doit pas même faire regarder le disque. Le double lève si on
     * l'interroge — la seule façon de prouver « pas d'accès disque » plutôt que
     * de l'affirmer.
     */
    public function test_malformed_identifier_never_reaches_the_filesystem(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects($this->never())->method('glob');
        $filesystem->expects($this->never())->method('isDirectory');

        $source = new LocalDirectoryRecordingMediaSource($filesystem, $this->root);

        $this->assertNull($source->locate('../../../etc'));
    }
}
