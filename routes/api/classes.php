<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\LMSClasseLocalDetailsController;
use App\Http\Controllers\API\LMS\LMSClassesController;
use App\Http\Controllers\API\LMS\LMSTeacherClassesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Classes — lecture
|--------------------------------------------------------------------------
|
| Extrait de `routes/api/lms.php` (#760), qui avait franchi les 300 lignes
| du §1.1. Le garde de taille n'inspecte que `app/**` : il ne pouvait pas le
| signaler, et la règle vaut quand même — « Exceptions : Aucune ».
|
| Les routes d'ÉCRITURE des classes restent dans `lms.php` : elles vivent
| dans le groupe du catalogue local (ADR-848-01), aux côtés des matières et
| du programme, et les séparer casserait un ensemble cohérent.
|
| Ce fichier porte DEUX portes vers la même ressource, dans deux espaces
| d'identifiants distincts. Le pourquoi est dans `ClasseLocalDetailsService`.
|
*/

Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('lms')->group(function () {
    // Détails d'une classe par son identifiant LOCAL (#760).
    //
    // Déclarée AVANT la route paramétrée : `local` est un segment littéral,
    // que `{classeId}` capturerait sinon comme valeur.
    Route::get('/classes/local/{classeId}', [LMSClasseLocalDetailsController::class, 'show'])
        ->whereNumber('classeId')
        ->name('lms.classes.details-local');

    // Détails complets d'une classe
    Route::get('/classes/{classeId}', [LMSClassesController::class, 'classeDetails'])
        ->name('lms.classes.details');

    // Étudiants d'une classe
    Route::get('/classes/{classeId}/etudiants', [LMSClassesController::class, 'classeEtudiants'])
        ->name('lms.classes.etudiants');

    Route::get('/teacher/classes', [LMSTeacherClassesController::class, 'index'])
        ->middleware('role:enseignant,coordinateur')
        ->name('lms.teacher.classes');
});
