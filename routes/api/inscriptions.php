<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\InscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Validation des inscriptions (#1658)
|--------------------------------------------------------------------------
|
| Les gestes de l'école sur une inscription (ligne `classe_etudiant`) :
| valider, annuler, réserve et sa levée, fiche ; et le réglage de l'école.
| Gestion d'une école autonome (`gestion`, puis `PorteDuCatalogueLocal`).
*/
Route::middleware(['auth:sanctum', 'klassci.sync', 'gestion'])->group(function (): void {
    Route::put('inscriptions/reglage', [InscriptionController::class, 'regler']);
    Route::put('inscriptions/{inscription}', [InscriptionController::class, 'fiche'])->whereNumber('inscription');
    Route::post('inscriptions/{inscription}/valider', [InscriptionController::class, 'valider'])->whereNumber('inscription');
    Route::post('inscriptions/{inscription}/annuler', [InscriptionController::class, 'annuler'])->whereNumber('inscription');
    Route::post('inscriptions/{inscription}/reserve', [InscriptionController::class, 'reserver'])->whereNumber('inscription');
    Route::post('inscriptions/{inscription}/lever-reserve', [InscriptionController::class, 'leverReserve'])->whereNumber('inscription');
});
