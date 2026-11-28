<?php

declare(strict_types=1);

use App\Http\Controllers\API\Eleve\DossierEleveController;
use App\Http\Controllers\API\Eleve\TuteurController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dossier de l'élève et tuteurs (#1657)
|--------------------------------------------------------------------------
|
| La LECTURE n'a pas de garde de rôle : qui lit quoi (gestion et élève
| lui-même en entier, enseignant d'une de ses classes en partie) est décidé
| par `DossierDeLEleve`. L'ÉCRITURE est la gestion d'une école autonome
| (`gestion`, puis `PorteDuCatalogueLocal`).
*/
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function (): void {
    Route::get('eleves/{eleve}/dossier', [DossierEleveController::class, 'show'])->whereNumber('eleve');
});

Route::middleware(['auth:sanctum', 'klassci.sync', 'gestion'])->group(function (): void {
    Route::put('eleves/{eleve}/dossier', [DossierEleveController::class, 'update'])->whereNumber('eleve');
    Route::post('eleves/{eleve}/tuteurs', [TuteurController::class, 'store'])->whereNumber('eleve');
    Route::put('tuteurs/{tuteur}', [TuteurController::class, 'update'])->whereNumber('tuteur');
    Route::delete('eleves/{eleve}/tuteurs/{tuteur}', [TuteurController::class, 'destroy'])->whereNumber(['eleve', 'tuteur']);
});
