<?php

declare(strict_types=1);

use App\Http\Controllers\API\Reinscription\ReglesDePassageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Règles de passage et réinscription (#1663)
|--------------------------------------------------------------------------
|
| PR 1 : les règles (école, surcharge par classe) et les propositions de
| passage d'une classe locale, avec leur correction motivée. Gestion d'une
| école autonome (`permission:formations.gerer`, comme les gestes
| d'inscription ; puis `PorteDuCatalogueLocal`).
*/
Route::middleware(['auth:sanctum', 'klassci.sync', 'permission:formations.gerer'])->group(function (): void {
    Route::get('regles-de-passage', [ReglesDePassageController::class, 'ecole']);
    Route::put('regles-de-passage', [ReglesDePassageController::class, 'ecrireEcole']);
    Route::get('classes/{classe}/regles-de-passage', [ReglesDePassageController::class, 'classe'])->whereNumber('classe');
    Route::put('classes/{classe}/regles-de-passage', [ReglesDePassageController::class, 'surcharger'])->whereNumber('classe');
    Route::delete('classes/{classe}/regles-de-passage', [ReglesDePassageController::class, 'retirer'])->whereNumber('classe');
    Route::get('classes/{classe}/passages', [ReglesDePassageController::class, 'passages'])->whereNumber('classe');
    Route::put('classes/{classe}/passages/{eleve}', [ReglesDePassageController::class, 'corriger'])->whereNumber(['classe', 'eleve']);
});
