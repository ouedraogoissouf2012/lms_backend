<?php

declare(strict_types=1);

use App\Http\Controllers\API\Dossier\PieceAFournirController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pièces à fournir et dossiers (#1661)
|--------------------------------------------------------------------------
|
| PR 1 : le catalogue des pièces, gestion d'une école autonome (`gestion`,
| puis `PorteDuCatalogueLocal`). Une pièce se désactive, elle ne se supprime
| pas. Les documents (PR 2) et le suivi (PR 3) suivront.
*/
Route::middleware(['auth:sanctum', 'klassci.sync', 'gestion'])->group(function (): void {
    Route::get('pieces-a-fournir', [PieceAFournirController::class, 'index']);
    Route::post('pieces-a-fournir', [PieceAFournirController::class, 'store']);
    Route::put('pieces-a-fournir/{piece}', [PieceAFournirController::class, 'update'])->whereNumber('piece');
});
