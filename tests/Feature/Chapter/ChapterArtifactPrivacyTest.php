<?php

declare(strict_types=1);

namespace Tests\Feature\Chapter;

use App\Services\FileConversion\ChapterArtifactStorage;
use App\Services\FileConversion\FileValidator;
use App\Services\FileConversion\PdfConverter;
use App\Services\FileConversion\PdfToPngRendererInterface;
use App\Services\FileConversion\PowerPointConverter;
use App\Services\FileConversion\WordConverter;
use App\Services\ConvertApiService;
use App\Support\Shell\ShellExecutorInterface;
use App\Support\Shell\ShellResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #598 (P1 — broken access control, pré-existant) — les artefacts de
 * conversion sensibles ne doivent JAMAIS atterrir sur le disque `public`.
 *
 * `storage/app/public/` est la racine du disque « public » Laravel, exposée en
 * HTTP **sans authentification** via le symlink `public/storage` (URLs
 * `/storage/...`). Le pipeline y écrivait :
 *
 *   - le **document source** téléversé par l'enseignant (les 3 convertisseurs) ;
 *   - le **HTML plein-texte** produit par LibreOffice (`WordConverter`) ;
 *   - le **PDF intermédiaire** LibreOffice (`PowerPointConverter`) — non listé
 *     par l'issue, mais c'est une conversion fidèle du source : le laisser
 *     public rendrait le correctif inopérant.
 *
 * Les **diapositives PNG** et les **vidéos** restent publiques (consommées par
 * `<img>` / `<video>`) : ce test le garde explicitement, pour qu'on ne
 * sur-corrige pas au prix de l'affichage des cours.
 *
 * @see .claude/specs/598-chapter-artifacts-private/design.md
 */
final class ChapterArtifactPrivacyTest extends TestCase
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    private ShellExecutorInterface&MockInterface $shell;
    private ConvertApiService&MockInterface $convertApi;
    private PdfToPngRendererInterface&MockInterface $pdfRenderer;
    private FileValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        /** @var ShellExecutorInterface&MockInterface $shell */
        $shell = Mockery::mock(ShellExecutorInterface::class);
        $this->shell = $shell;

        /** @var ConvertApiService&MockInterface $convertApi */
        $convertApi = Mockery::mock(ConvertApiService::class);
        $this->convertApi = $convertApi;

        /** @var PdfToPngRendererInterface&MockInterface $pdfRenderer */
        $pdfRenderer = Mockery::mock(PdfToPngRendererInterface::class);
        $this->pdfRenderer = $pdfRenderer;

        $this->validator = new FileValidator();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * R1 + R2 — Word : ni le `.docx` source, ni le HTML plein-texte ne doivent
     * être servables via `/storage/...`.
     */
    public function test_word_conversion_keeps_original_and_html_off_the_public_disk(): void
    {
        $chapterId = 601;
        $file = UploadedFile::fake()->create('cours.docx', 40, self::DOCX_MIME);

        $this->shell->shouldReceive('locate')->andReturn('/usr/bin/soffice');
        $this->shell->shouldReceive('run')->andReturnUsing(
            fn (array $command): ShellResult => $this->writeFakeLibreOfficeOutput($command, 'html', '<p>Contenu du cours</p>')
        );

        $result = $this->wordConverter()->convert($file, $chapterId);

        self::assertPublicDiskHasNo("chapters/{$chapterId}/original");
        self::assertPublicDiskHasNo("chapters/{$chapterId}/html");

        self::assertTrue(
            Storage::disk('local')->exists($result['file_original_path']),
            'Le document source doit vivre sur le disque privé.',
        );
    }

    /**
     * R1 + R2 — PowerPoint (repli LibreOffice) : le `.pptx` source **et** le PDF
     * intermédiaire doivent être privés ; les diapositives PNG restent publiques.
     */
    public function test_powerpoint_fallback_keeps_original_and_intermediate_pdf_off_the_public_disk(): void
    {
        $chapterId = 602;
        $file = UploadedFile::fake()->create('cours.pptx', 40, self::PPTX_MIME);

        $this->convertApi->shouldReceive('convertPowerPointToImages')
            ->andThrow(new RuntimeException('ConvertAPI indisponible'));

        $this->shell->shouldReceive('locate')->andReturn('/usr/bin/soffice');
        $this->shell->shouldReceive('run')->andReturnUsing(
            fn (array $command): ShellResult => $this->writeFakeLibreOfficeOutput($command, 'pdf', '%PDF-1.4 fake')
        );

        $this->pdfRenderer->shouldReceive('render')
            ->andReturn(["chapters/{$chapterId}/slides/slide_001.png"]);

        $result = $this->powerPointConverter()->convert($file, $chapterId);

        self::assertPublicDiskHasNo("chapters/{$chapterId}/original");
        self::assertPublicDiskHasNo("chapters/{$chapterId}/pdf");

        self::assertTrue(
            Storage::disk('local')->exists($result['file_original_path']),
            'Le document source doit vivre sur le disque privé.',
        );

        // R3 — garde anti-sur-correction : les diapositives restent publiques.
        self::assertSame(
            ["chapters/{$chapterId}/slides/slide_001.png"],
            $result['slides_images'],
            'Les diapositives PNG doivent rester sur le disque public (affichage <img>).',
        );

        // `relativePathOf()` est la seule méthode du correctif qui manipule des
        // chaînes avec un cas d'échec : elle doit produire un chemin RELATIF,
        // jamais la racine système du serveur (qui partirait en base puis en
        // réponse API).
        self::assertStringStartsWith(
            "chapters/{$chapterId}/pdf/",
            $result['file_converted_path'],
            'file_converted_path doit rester RELATIF au disque privé — un chemin absolu '
            . 'divulguerait l\'arborescence du serveur via la réponse API.',
        );
        self::assertStringEndsWith('.pdf', $result['file_converted_path']);
    }

    /**
     * R1 — PDF : le document source ne doit pas rester servable publiquement.
     */
    public function test_pdf_conversion_keeps_original_off_the_public_disk(): void
    {
        $chapterId = 603;
        $file = UploadedFile::fake()->create('cours.pdf', 40, 'application/pdf');

        $this->convertApi->shouldReceive('convertPdfToImages')
            ->andReturn(["chapters/{$chapterId}/slides/slide_001.png"]);

        $result = $this->pdfConverter()->convert($file, $chapterId);

        self::assertPublicDiskHasNo("chapters/{$chapterId}/original");

        self::assertTrue(
            Storage::disk('local')->exists($result['file_original_path']),
            'Le document source doit vivre sur le disque privé.',
        );
    }

    /**
     * R5 — Supprimer un chapitre doit purger les DEUX disques. Ne purger que le
     * public (comportement d'avant #598) laisserait le document de l'enseignant
     * orphelin sur le serveur.
     */
    public function test_deleting_a_chapter_purges_both_disks(): void
    {
        $chapterId = 604;

        Storage::disk('public')->put("chapters/{$chapterId}/slides/slide_001.png", 'png');
        Storage::disk('local')->put("chapters/{$chapterId}/original/source.docx", 'docx');

        app(\App\Services\FileConversionService::class)->deleteChapterFiles($chapterId);

        self::assertSame([], Storage::disk('public')->allFiles("chapters/{$chapterId}"));
        self::assertSame(
            [],
            Storage::disk('local')->allFiles("chapters/{$chapterId}"),
            'Le document source doit disparaître avec le chapitre, pas rester orphelin en privé.',
        );
    }

    /**
     * R6 — La commande de reprise du reliquat : simulation par défaut, et avec
     * `--apply` elle **migre** (copie puis supprime) au lieu de détruire.
     *
     * Ce point est le correctif d'un défaut HIGH relevé par l'audit
     * `spec-security` : une première version supprimait. Or, pour toute ligne
     * antérieure à #598, `file_original_path` pointe encore vers le chemin
     * public — supprimer aurait détruit définitivement le document de
     * l'enseignant, sans que la route authentifiée puisse prendre le relais.
     * Migrer **à chemin relatif constant** garde les lignes en base valides.
     */
    public function test_cleanup_command_migrates_instead_of_destroying(): void
    {
        $chapterId = 605;
        $original = "chapters/{$chapterId}/original/source.docx";
        $slide = "chapters/{$chapterId}/slides/slide_001.png";

        Storage::disk('public')->put($original, 'docx');
        Storage::disk('public')->put($slide, 'png');

        $this->artisan('chapters:purge-public-artifacts')->assertSuccessful();

        self::assertTrue(
            Storage::disk('public')->exists($original),
            'Sans --apply, la commande ne doit RIEN modifier.',
        );
        self::assertFalse(Storage::disk('local')->exists($original));

        $this->artisan('chapters:purge-public-artifacts', ['--apply' => true])->assertSuccessful();

        self::assertFalse(
            Storage::disk('public')->exists($original),
            'Le reliquat sensible doit quitter le disque public.',
        );
        self::assertSame(
            'docx',
            Storage::disk('local')->get($original),
            'Le document doit être RETROUVÉ en privé au MÊME chemin relatif — sans quoi '
            . 'la ligne en base devient orpheline et le document est perdu.',
        );
        self::assertTrue(
            Storage::disk('public')->exists($slide),
            'Les diapositives publiques ne doivent jamais être touchées (R3).',
        );
    }

    /**
     * Garde de configuration : le disque privé déclare `'serve' => true`, donc
     * Laravel expose une route `storage.local`. Elle n'est sûre que parce que le
     * disque ne déclare AUCUNE `visibility` — `ServeFile` exige alors une
     * signature valide. Ajouter `'visibility' => 'public'` annulerait #598 en une
     * ligne de config, sans qu'aucun test fonctionnel ne bronche.
     */
    public function test_private_disk_is_not_declared_publicly_visible(): void
    {
        self::assertNotSame(
            'public',
            config('filesystems.disks.local.visibility'),
            'Rendre le disque privé « public » exposerait TOUT storage/app/private '
            . 'sans authentification et annulerait #598.',
        );
    }

    // ------------------------------------------------------------------
    // Assertions
    // ------------------------------------------------------------------

    /**
     * Le disque public ne doit contenir AUCUN fichier sous ce préfixe — c'est la
     * traduction directe de « `GET /storage/{prefix}/...` ne renvoie rien ».
     */
    private static function assertPublicDiskHasNo(string $prefix): void
    {
        self::assertSame(
            [],
            Storage::disk('public')->allFiles($prefix),
            "Des fichiers sensibles restent servables sans authentification sous /storage/{$prefix} (#598).",
        );
    }

    // ------------------------------------------------------------------
    // Harnais
    // ------------------------------------------------------------------

    /**
     * Simule LibreOffice headless : écrit le fichier attendu dans le `--outdir`
     * reçu, avec le nom que le convertisseur reconstruit ensuite.
     *
     * @param  list<string>  $command
     */
    private function writeFakeLibreOfficeOutput(array $command, string $extension, string $contents): ShellResult
    {
        $source = $command[count($command) - 1];
        $outdir = $command[count($command) - 2];

        if (! is_dir($outdir)) {
            mkdir($outdir, 0755, true);
        }

        file_put_contents(
            $outdir . '/' . pathinfo($source, PATHINFO_FILENAME) . '.' . $extension,
            $contents,
        );

        return new ShellResult(stdout: '', stderr: '', exitCode: 0);
    }

    private function wordConverter(): WordConverter
    {
        return new WordConverter(new NullLogger(), $this->shell, $this->validator, $this->artifacts());
    }

    private function powerPointConverter(): PowerPointConverter
    {
        return new PowerPointConverter(
            new NullLogger(),
            $this->shell,
            $this->convertApi,
            $this->pdfRenderer,
            $this->validator,
            $this->artifacts(),
        );
    }

    private function pdfConverter(): PdfConverter
    {
        return new PdfConverter(
            new NullLogger(),
            $this->convertApi,
            $this->pdfRenderer,
            $this->validator,
            $this->artifacts(),
        );
    }

    /**
     * Vrai `ChapterArtifactStorage` résolu par le conteneur : il consomme la
     * `Filesystem\Factory` de l'application, donc les disques feints par
     * `Storage::fake()` — aucun double, la décision de disque réelle est testée.
     */
    private function artifacts(): ChapterArtifactStorage
    {
        return app(ChapterArtifactStorage::class);
    }
}
