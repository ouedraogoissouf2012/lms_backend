<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\LMSCalendrierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Calendrier de l'année (#1671)
|--------------------------------------------------------------------------
|
| Hors de `routes/api/lms.php`, proche des 300 lignes du §1.1 (précédent :
| `emplois-du-temps.php`, #1669). Même groupe que les routes `lms` :
| `auth:sanctum`, `klassci.sync`, préfixe `lms`. La lecture est ouverte à
| tout membre de l'école (le service applique la portée) ; l'écriture, à la
| gestion seule (`gestion`). Les deux volets : aucune porte du catalogue.
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms/calendrier')->group(function (): void {
    Route::get('/', [LMSCalendrierController::class, 'index'])->name('lms.calendrier.index');

    Route::middleware('gestion')->group(function (): void {
        Route::post('/', [LMSCalendrierController::class, 'store'])->name('lms.calendrier.store');
        Route::put('/{evenementId}', [LMSCalendrierController::class, 'update'])->whereNumber('evenementId')->name('lms.calendrier.update');
        Route::delete('/{evenementId}', [LMSCalendrierController::class, 'destroy'])->whereNumber('evenementId')->name('lms.calendrier.destroy');
    });
});
