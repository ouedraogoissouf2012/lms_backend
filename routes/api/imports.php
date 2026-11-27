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
| #1708 : le rôle `admin` rejoint les trois gardes. L'écran d'import s'ouvre
| à l'admin d'école, et la couche métier l'admettait déjà (`authorize()` de
| la requête, `isAdmin()` des contrôleurs) : seule la route le refusait.
| Le superAdmin n'a jamais eu besoin d'y figurer, `EnsureRole` le laisse
| passer partout ; il reste listé pour que la garde se lise d'elle-même.
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {
    Route::post('/imports/preview', [ImportPreviewController::class, 'store'])
        ->middleware(['role:enseignant,coordinateur,admin,superAdmin', 'throttle:imports'])
        ->name('lms.imports.preview');
    Route::post('/imports/{id}/confirm', [ImportConfirmController::class, 'store'])
        ->middleware(['role:enseignant,coordinateur,admin,superAdmin', 'throttle:imports'])
        ->whereNumber('id')
        ->name('lms.imports.confirm');
    Route::get('/imports/{id}', [ImportShowController::class, 'show'])
        ->middleware(['role:enseignant,coordinateur,admin,superAdmin'])
        ->whereNumber('id')
        ->name('lms.imports.show');
});
