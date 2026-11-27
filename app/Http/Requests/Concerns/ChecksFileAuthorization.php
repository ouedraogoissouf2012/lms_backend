<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\File;
use App\Models\User;
use App\Services\File\LecteurDeFichier;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * L'autorisation de MODÉRER un fichier (modifier, supprimer), partagée par
 * `UpdateFileRequest` et `DeleteFileRequest` (#1768).
 *
 * La règle vit dans {@see LecteurDeFichier}, la même que pour lire : le
 * propriétaire, ou la gestion de son école (`isManager`).
 *
 * Un fichier que l'appelant ne LIT pas répond comme un fichier absent : la même
 * `ModelNotFoundException` que la liaison de route `{file}` lève pour un id
 * inconnu, donc le même 404, au même corps. Un fichier qu'il lit sans pouvoir le
 * modérer (un fichier public d'autrui) reçoit 403 : son existence ne lui est pas
 * cachée.
 */
trait ChecksFileAuthorization
{
    protected function autoriserLaModeration(LecteurDeFichier $lecteur, mixed $fichier, mixed $appelant): bool
    {
        if (! $fichier instanceof File || ! $appelant instanceof User) {
            return false;
        }

        if (! $lecteur->peutLire($appelant, $fichier)) {
            throw (new ModelNotFoundException)->setModel(File::class, [$fichier->id]);
        }

        return $lecteur->peutModerer($appelant, $fichier);
    }
}
