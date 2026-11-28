<?php

declare(strict_types=1);

use App\Http\Controllers\API\Donnees\ExportDesDonneesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Données personnelles (#1705, loi 2013-450)
|--------------------------------------------------------------------------
|
| L'export des données d'un élève, à sa demande, par l'ADMINISTRATION de son
| école (`permission:eleves.exporter` : admin et superAdmin) : un acte de
| responsable de traitement, pas du coordinateur. Les deux volets. Journalisé.
*/
Route::middleware(['auth:sanctum', 'klassci.sync', 'permission:eleves.exporter', 'throttle:export-donnees'])->group(function (): void {
    Route::get('eleves/{eleve}/export', [ExportDesDonneesController::class, 'show'])->whereNumber('eleve');
});
