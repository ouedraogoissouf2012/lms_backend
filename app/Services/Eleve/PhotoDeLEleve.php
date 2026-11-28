<?php

declare(strict_types=1);

namespace App\Services\Eleve;

use App\Exceptions\BusinessException;
use App\Models\DossierEleve;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use Illuminate\Contracts\Filesystem\Factory as Disques;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * La photo de l'élève d'une école autonome (#1657, PR 2).
 *
 * - Déposer : la gestion (`PorteDuCatalogueLocal`), sur un élève qui a un dossier (409 `dossier_absent`). L'image est
 *   RÉ-ENCODÉE ({@see ReencodeurDePhoto}) ; ce qui ne se décode pas est refusé (422 sur `photo`). L'ancienne photo
 *   est retirée.
 * - Stockage PRIVÉ, disque `local`, sous `eleves/{école}/{élève}/` : la purge du compte retire ce dossier entier.
 * - Lire : les règles de lecture du dossier ({@see DossierDeLEleve::lecteur()}).
 */
final class PhotoDeLEleve
{
    public const DISQUE = 'local';

    public function __construct(
        private readonly PorteDuCatalogueLocal $porte,
        private readonly DossierDeLEleve $dossiers,
        private readonly ReencodeurDePhoto $reencodeur,
        private readonly Disques $disques,
    ) {}

    /** Le répertoire privé des fichiers d'un élève. */
    public static function repertoire(int $ecole, int $eleveId): string
    {
        return "eleves/{$ecole}/{$eleveId}";
    }

    /**
     * @return bool vrai si c'est la première photo de l'élève
     *
     * @throws BusinessException 403, 404, 409 `dossier_absent`
     * @throws ValidationException 422 sur `photo`
     */
    public function deposer(User $acteur, int $eleveId, UploadedFile $fichier): bool
    {
        $ecole = $this->porte->etablissement();
        $dossier = $this->dossiers->dossierExistant($acteur, $eleveId);

        $jpeg = $this->reencodeur->jpeg((string) $fichier->get());
        if ($jpeg === null) {
            throw ValidationException::withMessages(['photo' => 'Ce fichier ne se lit pas comme une image.']);
        }

        $chemin = self::repertoire($ecole, $dossier->user_id).'/photo-'.Str::uuid().'.jpg';
        $this->disque()->put($chemin, $jpeg);
        $ancien = $dossier->photo_chemin;
        $dossier->update(['photo_chemin' => $chemin]);
        if (is_string($ancien) && $ancien !== '') {
            $this->disque()->delete($ancien);
        }

        return $ancien === null;
    }

    /** @throws BusinessException 403, 404, 409 `dossier_absent` */
    public function retirer(User $acteur, int $eleveId): void
    {
        $this->porte->etablissement();
        $dossier = $this->dossiers->dossierExistant($acteur, $eleveId);
        $ancien = $dossier->photo_chemin;
        $dossier->update(['photo_chemin' => null]);
        if (is_string($ancien) && $ancien !== '') {
            $this->disque()->delete($ancien);
        }
    }

    /**
     * Le chemin privé de la photo, pour un lecteur admis.
     *
     * @throws BusinessException 403, 404 (élève, ou pas de photo)
     */
    public function chemin(User $acteur, int $eleveId): string
    {
        $eleve = $this->dossiers->lecteur($acteur, $eleveId)['eleve'];
        $chemin = DossierEleve::query()->withoutGlobalScopes()->where('user_id', $eleve->id)->value('photo_chemin');

        if (! is_string($chemin) || ! $this->disque()->exists($chemin)) {
            throw new BusinessException('Aucune photo pour cet élève.', 404);
        }

        return $chemin;
    }

    public function disque(): Filesystem
    {
        return $this->disques->disk(self::DISQUE);
    }
}
