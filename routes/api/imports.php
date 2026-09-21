<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\ImportConfirmController;
use App\Http\Controllers\API\LMS\ImportPreviewController;
use App\Http\Controllers\API\LMS\ImportShowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Imports d'effectifs (#846)
|--------------------------------------------------------------------------
|
| Extrait de `routes/api/lms.php` (#760) pour ramener ce fichier sous les
| 300 lignes du §1.1. Domaine autonome : téléverser, prévisualiser, puis
| confirmer — rien de commun avec les classes ou les matières.
|
| Les trois routes gardent leur garde de rôle et leur limitation de débit
| à l'identique : ce déplacement ne change aucun comportement, et la table
| de routage complète a été comparée avant/après.
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {
    Route::post('/imports/preview', [ImportPreviewController::class, 'store'])
        ->middleware(['role:enseignant,coordinateur,superAdmin', 'throttle:10,1'])
        ->name('lms.imports.preview');
    Route::post('/imports/{id}/confirm', [ImportConfirmController::class, 'store'])
        ->middleware(['role:enseignant,coordinateur,superAdmin', 'throttle:10,1'])
        ->whereNumber('id')
        ->name('lms.imports.confirm');
    Route::get('/imports/{id}', [ImportShowController::class, 'show'])
        ->middleware(['role:enseignant,coordinateur,superAdmin'])
        ->whereNumber('id')
        ->name('lms.imports.show');
});
