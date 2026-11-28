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
| #1708 : l'admin d'école importe. L'écran d'import s'ouvre à lui, et la
| couche métier l'admettait déjà (`authorize()` de la requête, `isAdmin()`
| des contrôleurs) : seule la route le refusait. Depuis #1665, la garde est
| `permission:imports.importer`, que `PermissionsDuRole` donne à l'admin.
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {
    Route::post('/imports/preview', [ImportPreviewController::class, 'store'])
        ->middleware(['permission:imports.importer', 'throttle:imports'])
        ->name('lms.imports.preview');
    Route::post('/imports/{id}/confirm', [ImportConfirmController::class, 'store'])
        ->middleware(['permission:imports.importer', 'throttle:imports'])
        ->whereNumber('id')
        ->name('lms.imports.confirm');
    Route::get('/imports/{id}', [ImportShowController::class, 'show'])
        ->middleware(['permission:imports.importer'])
        ->whereNumber('id')
        ->name('lms.imports.show');
});
