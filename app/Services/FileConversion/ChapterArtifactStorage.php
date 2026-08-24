<?php

declare(strict_types=1);

namespace App\Services\FileConversion;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Issue #598 — **seule autorité** sur « quel artefact de chapitre vit sur quel
 * disque ».
 *
 * ## Le défaut corrigé
 *
 * `storage/app/public/` est la racine du disque « public » Laravel, servie en
 * HTTP **sans authentification** via le symlink `public/storage` (URLs
 * `/storage/...`). Le pipeline de conversion y déposait le **document source**
 * téléversé, le **HTML plein-texte** LibreOffice et le **PDF intermédiaire** —
 * tous lisibles par n'importe quel tiers connaissant l'URL, hors de
 * `FileController::download()` et de tout contrôle d'institution.
 *
 * ## Pourquoi une classe plutôt que 5 remplacements de `'public'` par `'local'`
 *
 * Le littéral `'public'` et le chemin `storage_path('app/public/...')` étaient
 * dupliqués dans les 3 convertisseurs, plus une fois dans la purge. Un
 * search-replace corrigerait l'instant présent et laisserait le piège intact :
 * le prochain convertisseur (audio ? epub ?) recopierait le motif voisin.
 * Concentrer la décision dans une classe dont le nom énonce la règle rend la
 * faute visible en revue.
 *
 * ## Ce qui reste public — délibérément
 *
 * Les **vidéos** (`chapters/{id}/video/`) et les **diapositives PNG**
 * (`chapters/{id}/slides/`) sont consommées par des balises `<video>` / `<img>`.
 * Les rendre privées casserait l'affichage des cours : ce ne sont pas les
 * documents sources mais des rendus destinés à la diffusion. Les vidéos passent
 * malgré tout par {@see self::storeVideo()}, pour que le choix du disque reste
 * déclaré **ici** et jamais recopié dans un service appelant.
 *
 * ## Périmètre NON couvert — signalé, pas tu
 *
 * Les diapositives sont écrites par `PdfToPngRenderer::render()` et
 * `ConvertApiService`, qui reçoivent un dossier de sortie et non un disque.
 * Elles restent donc hors de cette classe. Surtout : leurs URLs sont
 * **prédictibles** (`chapters/{id}/slides/slide_001.png`, `chapters.id` étant un
 * entier séquentiel global), donc le contenu page à page des cours reste
 * énumérable sans authentification. C'est **pré-existant** à #598 et hors de son
 * périmètre, mais ce n'est PAS refermé — cf. l'issue de suivi et
 * `.claude/specs/598-chapter-artifacts-private/design.md` § dette.
 *
 * @see .claude/specs/598-chapter-artifacts-private/design.md
 */
final class ChapterArtifactStorage
{
    /**
     * Disque privé (`storage/app/private/`).
     *
     * ⚠️ Précision qui compte : `config/filesystems.php` déclare `'serve' => true`
     * sur ce disque, donc Laravel enregistre une route `storage.local`. Ce n'est
     * pas un trou : le disque ne déclare aucune `visibility`, si bien que
     * `Illuminate\Filesystem\ServeFile` exige une **signature valide** et répond
     * 404 sans elle — un test de garde verrouille cette configuration. Deux
     * conséquences à retenir : ajouter `'visibility' => 'public'` à ce disque
     * annulerait #598 en une ligne de config ; et toute URL signée générée à
     * l'avenir (`temporaryUrl()`) servirait le document **hors** de
     * {@see \App\Http\Requests\Concerns\ChecksChapterDownloadAuthorization}.
     */
    public const PRIVATE_DISK = 'local';

    /** Disque public (`storage/app/public/`) — servi via le symlink `/storage`. */
    public const PUBLIC_DISK = 'public';

    /** Sous-dossiers d'artefacts sensibles, stockés en privé et purgés ensemble. */
    public const PRIVATE_KINDS = ['original', 'html', 'pdf'];

    public function __construct(
        private readonly FilesystemFactory $filesystem,
    ) {
    }

    /**
     * Persiste le document source téléversé sur le disque **privé** et renvoie
     * son chemin relatif (celui stocké dans `chapters.file_original_path`).
     *
     * @throws RuntimeException si l'écriture échoue.
     */
    public function storeOriginal(UploadedFile $file, int $chapterId): string
    {
        // Passer par `privateDisk()` et non par le NOM du disque : si le disque
        // n'était pas un adaptateur local, on échoue AVANT d'écrire, plutôt que
        // de laisser un fichier orphelin puis d'exploser sur `absolutePath()`.
        $path = $this->privateDisk()->putFile($this->relativeDirectory($chapterId, 'original'), $file);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Échec sauvegarde du fichier original');
        }

        return $path;
    }

    /**
     * Persiste une vidéo de chapitre sur le disque **public** — et l'assume.
     *
     * La vidéo est lue par `<video src="/storage/...">` : la rendre privée
     * casserait la lecture des cours. Elle passe malgré tout par cette classe
     * pour que « seule autorité sur le choix du disque » reste vrai — sans quoi
     * un `store(..., 'public')` subsisterait dans un service de chapitre,
     * exactement le motif que ce collaborateur existe pour éliminer.
     *
     * @throws RuntimeException si l'écriture échoue.
     */
    public function storeVideo(UploadedFile $file, int $chapterId): string
    {
        $path = $file->store($this->relativeDirectory($chapterId, 'video'), self::PUBLIC_DISK);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Échec sauvegarde de la vidéo');
        }

        return $path;
    }

    /**
     * Nom du disque qui détient réellement cet artefact, ou `null` s'il est
     * introuvable.
     *
     * `chapters.file_original_path` est **polymorphe** : privé pour les documents
     * convertis (pdf / word / powerpoint), public pour les vidéos, qui doivent
     * rester lisibles par `<video>`. Un consommateur qui coderait un disque en
     * dur renverrait donc 404 en silence sur tout chapitre vidéo — c'est arrivé,
     * l'audit `spec-architect` l'a attrapé. Le privé est interrogé en premier.
     */
    public function diskHolding(string $relativePath): ?string
    {
        foreach ([self::PRIVATE_DISK, self::PUBLIC_DISK] as $disk) {
            if ($this->filesystem->disk($disk)->exists($relativePath)) {
                return $disk;
            }
        }

        return null;
    }

    /**
     * Chemin absolu, sur le disque privé, d'un chemin relatif — nécessaire pour
     * passer le fichier à LibreOffice / Ghostscript, qui prennent des chemins
     * système et non des abstractions Flysystem.
     */
    public function absolutePath(string $relativePath): string
    {
        return $this->privateDisk()->path($relativePath);
    }

    /**
     * Dossier de travail **privé** d'un artefact dérivé (`html`, `pdf`), créé au
     * besoin. Renvoie un chemin absolu, exploitable comme `--outdir`.
     */
    public function workDirectory(int $chapterId, string $kind): string
    {
        $relative = $this->relativeDirectory($chapterId, $kind);
        $this->privateDisk()->makeDirectory($relative);

        return $this->absolutePath($relative);
    }

    /**
     * Repasse d'un chemin absolu du disque privé à son chemin relatif — utilisé
     * pour persister `chapters.file_converted_path` sans y écrire la racine
     * système du serveur.
     *
     * **Échoue bruyamment** hors racine plutôt que de renvoyer le chemin absolu :
     * un repli silencieux écrirait l'arborescence du serveur en base — puis dans
     * la réponse API, le modèle étant sérialisé brut — c'est-à-dire exactement
     * ce que cette méthode existe pour éviter. Le seul appelant fournit un chemin
     * fabriqué par {@see self::workDirectory()} : hors racine = bug, jamais cas
     * nominal.
     *
     * La comparaison est sensible à la casse : un chemin retraité par `realpath()`
     * (qui capitalise la lettre de lecteur sous Windows) lèvera l'exception.
     * C'est voulu — mieux vaut un échec net qu'une donnée fausse persistée.
     *
     * @throws RuntimeException si le chemin ne descend pas du disque privé.
     */
    public function relativePathOf(string $absolutePath): string
    {
        $root = rtrim(str_replace('\\', '/', $this->absolutePath('')), '/') . '/';
        $normalised = str_replace('\\', '/', $absolutePath);

        if (! str_starts_with($normalised, $root)) {
            throw new RuntimeException(
                'Chemin hors du disque privé des chapitres : impossible de le persister en relatif.'
            );
        }

        return substr($normalised, strlen($root));
    }

    /**
     * Purge tous les artefacts d'un chapitre — sur les **deux** disques.
     *
     * Purger le seul disque public (comportement d'avant #598) laisserait
     * désormais les documents sources orphelins en privé : supprimer un chapitre
     * doit vraiment faire disparaître le document de l'enseignant.
     */
    public function purgeChapter(int $chapterId): void
    {
        $directory = "chapters/{$chapterId}";

        $this->filesystem->disk(self::PUBLIC_DISK)->deleteDirectory($directory);
        $this->filesystem->disk(self::PRIVATE_DISK)->deleteDirectory($directory);
    }

    private function relativeDirectory(int $chapterId, string $kind): string
    {
        return "chapters/{$chapterId}/{$kind}";
    }

    /**
     * Le disque privé doit être un adaptateur local : `path()` (traduction en
     * chemin système, indispensable aux binaires de conversion) n'existe pas sur
     * le contrat `Filesystem`, seulement sur {@see FilesystemAdapter}. On
     * l'affirme explicitement plutôt que de laisser passer un appel non typé.
     *
     * Résolution **paresseuse** et non au constructeur : `FileConversionService`
     * est un singleton, et résoudre le disque à la construction ferait manquer un
     * `Storage::fake()` posé après coup.
     */
    private function privateDisk(): FilesystemAdapter
    {
        $disk = $this->filesystem->disk(self::PRIVATE_DISK);

        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException(
                'Le disque privé des chapitres doit être un adaptateur local (chemin système requis).'
            );
        }

        return $disk;
    }
}
