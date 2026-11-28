<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\LMSEmploiDuTempsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Emplois du temps — la grille hebdomadaire d'une classe (#1669)
|--------------------------------------------------------------------------
|
| Hors de `routes/api/lms.php`, déjà proche des 300 lignes du §1.1 (précédent :
| `sessions.php`, #918). Même groupe que les routes `lms` : `auth:sanctum`,
| `klassci.sync`, préfixe `lms` ; la gestion seule (`gestion`). Chargé juste APRÈS
| `lms.php` par `routes/api.php`. Les alertes sont déclarées AVANT toute route
| `/{emploiId}` : aucune ne les masque.
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync', 'gestion'])->prefix('lms/emplois-du-temps')->group(function (): void {
    Route::get('/alertes', [LMSEmploiDuTempsController::class, 'alertes'])->name('lms.emplois-du-temps.alertes');
    Route::get('/', [LMSEmploiDuTempsController::class, 'index'])->name('lms.emplois-du-temps.index');
    Route::post('/', [LMSEmploiDuTempsController::class, 'store'])->name('lms.emplois-du-temps.store');
    Route::get('/{emploiId}', [LMSEmploiDuTempsController::class, 'show'])->whereNumber('emploiId')->name('lms.emplois-du-temps.show');
    Route::put('/{emploiId}', [LMSEmploiDuTempsController::class, 'update'])->whereNumber('emploiId')->name('lms.emplois-du-temps.update');
    Route::delete('/{emploiId}', [LMSEmploiDuTempsController::class, 'destroy'])->whereNumber('emploiId')->name('lms.emplois-du-temps.destroy');
    Route::put('/{emploiId}/lignes', [LMSEmploiDuTempsController::class, 'lignes'])->whereNumber('emploiId')->name('lms.emplois-du-temps.lignes');
    Route::post('/{emploiId}/generer', [LMSEmploiDuTempsController::class, 'generer'])->whereNumber('emploiId')->name('lms.emplois-du-temps.generer');
    Route::post('/{emploiId}/copier', [LMSEmploiDuTempsController::class, 'copier'])->whereNumber('emploiId')->name('lms.emplois-du-temps.copier');
});
