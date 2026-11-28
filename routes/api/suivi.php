<?php

use App\Http\Controllers\API\LMS\LMSClasseDecrochageController;
use App\Http\Controllers\API\LMS\ReglagesDuDecrochageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Suivi des élèves (#1704)
|--------------------------------------------------------------------------
|
| L'alerte des élèves qui décrochent : trois règles expliquées, des seuils
| réglés par l'école. La liste d'une classe suit la porte de son bilan
| (`/classes/{classe}/resultats`) : l'id de l'ESPACE SOURCE, l'accès décidé
| par `AccesAuBilanDeClasse`, et `classes.eleves` (le personnel) pour qu'aucun élève n'y entre.
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {
    Route::get('/classes/{classe}/decrochage', [LMSClasseDecrochageController::class, 'show'])
        ->whereNumber('classe')
        ->middleware('permission:classes.eleves')
        ->name('lms.classes.decrochage');

    Route::middleware('permission:suivi.reglages')->group(function () {
        Route::get('/suivi/decrochage/reglages', [ReglagesDuDecrochageController::class, 'show'])
            ->name('lms.suivi.decrochage.reglages');
        Route::put('/suivi/decrochage/reglages', [ReglagesDuDecrochageController::class, 'update'])
            ->name('lms.suivi.decrochage.reglages.update');
    });
});
