<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\LMSHeuresEnseignantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Heures des enseignants (#1682)
|--------------------------------------------------------------------------
|
| Hors de `routes/api/lms.php`, arrivé aux 300 lignes du §1.1 (précédents :
| `emplois-du-temps.php`, #1669 ; `calendrier.php`, #1671). Même groupe que
| les routes `lms` : `auth:sanctum`, `klassci.sync`, préfixe `lms`. Les heures
| sont tirées de la visio (`HeuresEmargees`) ; la source de l'école
| (`HeuresDeLEnseignant`) est choisie au point de liaison.
|
| L'enseignant lit les SIENNES ; la gestion, le rapport de l'école.
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function (): void {
    Route::get('/mes-heures', [LMSHeuresEnseignantController::class, 'mesHeures'])
        ->name('lms.mes-heures')
        ->middleware('role:enseignant');
    Route::get('/heures-enseignants', [LMSHeuresEnseignantController::class, 'rapport'])
        ->name('lms.heures-enseignants')
        ->middleware('gestion');
});
