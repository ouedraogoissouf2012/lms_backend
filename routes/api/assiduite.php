<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\LMSJustificatifController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Assiduité — justificatifs d'absence (#1681)
|--------------------------------------------------------------------------
|
| Hors de `routes/api/lms.php`, à la limite des 300 lignes du §1.1 (précédent :
| `sessions.php`, #918). Même groupe que les routes `lms` : `auth:sanctum`,
| `klassci.sync`, préfixe `lms`. Chargé juste APRÈS `lms.php` par `routes/api.php` ;
| aucun chemin ne s'y croise avec ceux de `lms.php`.
|
| L'élève justifie (`role:etudiant`) ; la gestion tranche (`gestion`, puis la source,
| qui exclut le compte de plateforme) ; la pièce se lit par l'élève déposant ou la
| gestion de son école, la source refusant tout autre lecteur (404). La source de
| l'école (JustificationDAbsence) est choisie au point de liaison.
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function (): void {
    Route::get('/mes-absences', [LMSJustificatifController::class, 'mesAbsences'])
        ->name('lms.mes-absences')
        ->middleware('role:etudiant');
    Route::get('/mes-justificatifs', [LMSJustificatifController::class, 'mesJustificatifs'])
        ->name('lms.mes-justificatifs')
        ->middleware('role:etudiant');
    Route::post('/justificatifs', [LMSJustificatifController::class, 'store'])
        ->name('lms.justificatifs.store')
        ->middleware('role:etudiant');
    Route::get('/justificatifs', [LMSJustificatifController::class, 'index'])
        ->name('lms.justificatifs.index')
        ->middleware('gestion');
    Route::post('/justificatifs/{justificatifId}/valider', [LMSJustificatifController::class, 'valider'])
        ->whereNumber('justificatifId')
        ->name('lms.justificatifs.valider')
        ->middleware('gestion');
    Route::post('/justificatifs/{justificatifId}/rejeter', [LMSJustificatifController::class, 'rejeter'])
        ->whereNumber('justificatifId')
        ->name('lms.justificatifs.rejeter')
        ->middleware('gestion');
    // La pièce : l'élève déposant ou la gestion de son école ; la source refuse tout autre lecteur (404).
    Route::get('/justificatifs/{justificatifId}/piece', [LMSJustificatifController::class, 'piece'])
        ->whereNumber('justificatifId')
        ->name('lms.justificatifs.piece');
});
