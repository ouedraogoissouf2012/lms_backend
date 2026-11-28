<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\LMSProfilEnseignantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Profil et disponibilités d'un enseignant (#1666)
|--------------------------------------------------------------------------
|
| Hors de `routes/api/lms.php`, proche des 300 lignes du §1.1. Même groupe que
| les routes `lms` : `auth:sanctum`, `klassci.sync`, préfixe `lms`.
| `{enseignantId}` est l'id LOCAL du compte, dans les deux volets. La lecture est
| ouverte à la gestion et à l'enseignant lui-même (le service désigne) ;
| l'écriture, à la gestion seule (`gestion`).
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms/enseignants/{enseignantId}')->whereNumber('enseignantId')->group(function (): void {
    Route::get('/profil', [LMSProfilEnseignantController::class, 'profil'])->name('lms.enseignants.profil');
    Route::get('/disponibilites', [LMSProfilEnseignantController::class, 'disponibilites'])->name('lms.enseignants.disponibilites');

    Route::middleware('gestion')->group(function (): void {
        Route::put('/profil', [LMSProfilEnseignantController::class, 'ecrireProfil'])->name('lms.enseignants.profil.ecrire');
        Route::put('/disponibilites', [LMSProfilEnseignantController::class, 'semaine'])->name('lms.enseignants.disponibilites.semaine');
        Route::post('/disponibilites/exceptions', [LMSProfilEnseignantController::class, 'ajouterException'])->name('lms.enseignants.disponibilites.exceptions.store');
        Route::delete('/disponibilites/exceptions/{exceptionId}', [LMSProfilEnseignantController::class, 'retirerException'])
            ->whereNumber('exceptionId')->name('lms.enseignants.disponibilites.exceptions.destroy');
    });
});
