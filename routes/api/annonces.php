<?php

declare(strict_types=1);

use App\Http\Controllers\API\Annonce\AnnonceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Annonces (#1650)
|--------------------------------------------------------------------------
|
| Le personnel de l'école annonce à toute une classe. Le supradmin de
| plateforme n'en fait pas partie : il gère les écoles, pas leur vie de classe.
| La classe (dans l'école de l'acteur, et parmi les siennes pour un
| enseignant) est jugée par `AnnonceAUneClasse`. Limite nommée `annonce` :
| une rafale inonderait les élèves.
*/
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin,superAdmin', 'throttle:annonce'])
    ->post('annonces', [AnnonceController::class, 'store']);
