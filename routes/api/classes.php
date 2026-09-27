<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\LMSClasseLocalDetailsController;
use App\Http\Controllers\API\LMS\LMSClassesController;
use App\Http\Controllers\API\LMS\LMSClassesLocalesController;
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

    // Étudiants d'une classe — réservé au personnel (GHSA-gg7j) : cette route
    // n'existe que pour lister des élèves, et un élève ne reçoit jamais de
    // données sur un autre élève. Même garde que /seances/{id}/attendances.
    Route::get('/classes/{classeId}/etudiants', [LMSClassesController::class, 'classeEtudiants'])
        ->middleware('role:enseignant,coordinateur,admin')
        ->name('lms.classes.etudiants');

    Route::get('/teacher/classes', [LMSTeacherClassesController::class, 'index'])
        ->middleware('role:enseignant,coordinateur')
        ->name('lms.teacher.classes');
});

// La collection des classes LOCALES et de leur code (#905, ADR-905-01). Rien
// ne les listait — la liste d'administration vient de KLASSCI, et la porte
// locale de #760 répond 409 à une classe sans identifiant KLASSCI. Le code
// revient avec la classe : le relire ne le régénère plus, donc n'invalide plus
// celui déjà dicté.
//
// Hors du préfixe `lms` : son URL, `/api/classes`, est celle de la création
// (`lms.php`), dont elle est la collection. Déplacée ici par #924 — une
// lecture n'avait rien à faire dans le fichier des écritures.
//
// Même garde de rôle que l'émission du code : un apprenant qui lirait les codes
// des autres classes y entrerait sans y avoir été invité.
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:coordinateur,admin,superAdmin'])
    ->get('/classes', [LMSClassesLocalesController::class, 'index']);
