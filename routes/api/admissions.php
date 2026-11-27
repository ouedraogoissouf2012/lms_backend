<?php

declare(strict_types=1);

use App\Http\Controllers\API\Admission\DemandeInscriptionController;
use App\Http\Controllers\API\Admission\DepotDeDemandeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Demandes d'inscription (#1659)
|--------------------------------------------------------------------------
|
| La porte PUBLIQUE : sans jeton, l'école désignée dans le corps (et non par
| `institution.header`, qui répondrait 400 à une école inconnue et la
| confirmerait). La réponse est la même dans tous les cas. Limite nommée
| `demandes-inscription` par adresse ; le plafond par école est silencieux.
|
| La gestion (`gestion`, puis `PorteDuCatalogueLocal`) : liste, consultation,
| examen, rejet.
*/
Route::post('demandes-inscription', [DepotDeDemandeController::class, 'store'])
    ->middleware('throttle:demandes-inscription');

Route::middleware(['auth:sanctum', 'klassci.sync', 'gestion'])->group(function (): void {
    Route::get('demandes-inscription', [DemandeInscriptionController::class, 'index']);
    Route::get('demandes-inscription/{demande}', [DemandeInscriptionController::class, 'show'])->whereNumber('demande');
    Route::post('demandes-inscription/{demande}/examiner', [DemandeInscriptionController::class, 'examiner'])->whereNumber('demande');
    Route::post('demandes-inscription/{demande}/rejeter', [DemandeInscriptionController::class, 'rejeter'])->whereNumber('demande');
});
