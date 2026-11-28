<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\LMSSuiviDesPresencesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Suivi des présences de l'école (#1683)
|--------------------------------------------------------------------------
|
| Hors de `routes/api/lms.php`, arrivé aux 300 lignes du §1.1 (précédents :
| `emplois-du-temps.php`, #1669 ; `calendrier.php`, #1671). Même groupe que
| les routes `lms` : `auth:sanctum`, `klassci.sync`, préfixe `lms`. La gestion
| seule : `pilotage.consulter` (tableaux, statistiques, rapports ; coordinateur
| et administrateurs, #1760). La source de l'école (`SuiviDesPresences`) est
| choisie au point de liaison.
|
| La synthèse est déclarée AVANT toute route `/presences/{…}` : aucune ne la
| masque.
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync', 'permission:pilotage.consulter'])->prefix('lms/presences')->group(function (): void {
    Route::get('/synthese', [LMSSuiviDesPresencesController::class, 'synthese'])->name('lms.presences.synthese');
    Route::get('/', [LMSSuiviDesPresencesController::class, 'index'])->name('lms.presences');
});
