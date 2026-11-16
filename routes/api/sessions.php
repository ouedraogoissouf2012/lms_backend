<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\LMSClasseEnrolmentCodeController;
use App\Http\Controllers\API\LMS\LMSClasseMatiereController;
use App\Http\Controllers\API\LMS\LMSClasseWriteController;
use App\Http\Controllers\API\LMS\LMSMatiereWriteController;
use App\Http\Controllers\API\LMS\TrainingSessionController;
use App\Http\Controllers\API\LMS\TrainingSessionTransitionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Session de formation et catalogue local — écriture
|--------------------------------------------------------------------------
|
| Extrait de `routes/api/lms.php` (#918), qui avait franchi les 300 lignes du
| §1.1. Chargé juste AVANT `lms.php` par `routes/api.php`, donc toujours après
| `classes.php` : l'ordre d'enregistrement des routes qui se croisent ne change
| pas, et la liste des routes est identique avant et après (preuve dans la PR).
|
*/

// ============================================
// SESSION DE FORMATION - Programme et Periode (#827)
// ============================================
// L entite racine du monde autonome. #800 a livre les tables ; ces routes sont
// ce qui manquait pour s en servir.
//
// AUCUNE lecture du mode d etablissement : les tables sont tenant-scopees, et
// le contrat OCP (#697 art. 1) veut que les appelants recoivent une capacite,
// jamais un mode.
//
// `role:coordinateur,admin,superAdmin` : organiser une formation est un acte de
// gestion. Un enseignant anime, il ne date pas la session ; un etudiant la suit.
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:coordinateur,admin,superAdmin'])
    ->group(function () {
        Route::post('/programs', [TrainingSessionController::class, 'storeProgram']);
        Route::get('/training-sessions', [TrainingSessionController::class, 'index']);
        Route::post('/training-sessions', [TrainingSessionController::class, 'store']);

        // Faire avancer une Periode (#845, ADR-845-01). Cinq etats sur six
        // etaient inatteignables : TrainingSessionCrudService:55 ecrivait
        // Brouillon et rien ne le faisait avancer.
        //
        // Quatre operations NOMMEES et non un `PATCH status` : un statut libre
        // laisserait passer brouillon -> archivee, ou le retour d une periode
        // annulee. `purgee` n est atteignable par AUCUNE route — ce n est pas
        // une decision humaine mais l issue d une politique de retention.
        Route::post('/training-sessions/{trainingSession}/publier', [TrainingSessionTransitionController::class, 'publier']);
        Route::post('/training-sessions/{trainingSession}/annuler', [TrainingSessionTransitionController::class, 'annuler']);
        Route::post('/training-sessions/{trainingSession}/cloturer', [TrainingSessionTransitionController::class, 'cloturer']);
        Route::post('/training-sessions/{trainingSession}/archiver', [TrainingSessionTransitionController::class, 'archiver']);

        // Creer une classe SANS KLASSCI (#848). Le seul createur d une Classe
        // etait jusqu ici la synchronisation : une ecole autonome n en avait
        // aucune, jamais, et l import CSV produisait des apprenants qui
        // n appartenaient a rien.
        //
        // Meme groupe, meme garde de role : composer son catalogue est un acte
        // de gestion, au meme titre que dater une session. Le DROIT d ecrire
        // localement est verifie plus bas, au point d ecriture, par une
        // capacite — la route ne lit aucun mode.
        Route::post('/classes', [LMSClasseWriteController::class, 'store']);
        // Sa collection, une LECTURE, vit dans `routes/api/classes.php` (#924).

        // Creer une matiere SANS KLASSCI (#797, #848). Second des trois verrous
        // de l ADR : la classe n exigeait que l ecrivain, la matiere exigeait
        // d abord de lever `matieres.klassci_id` NOT NULL. Sans elle, une ecole
        // autonome avait des classes mais rien a y enseigner.
        Route::post('/matieres', [LMSMatiereWriteController::class, 'store']);

        // Composer le programme d une classe (#848) : rattacher une matiere, et
        // y designer un formateur. Ecrit `classe_matiere`, le pivot LOCAL que
        // LocalEnrollmentSource:35-40 lit deja — `matiere_enseignant` n est pas
        // touchee, c est le miroir KLASSCI.
        Route::post('/classes/{classe}/matieres', [LMSClasseMatiereController::class, 'store']);

        // Le code d inscription d une classe (#846, ADR-803-03). Rendu EN CLAIR
        // — il circule par telephone et se reaffiche, contrairement au lien
        // d activation qui, lui, est hache et a usage unique.
        //
        // Emettre remplace le precedent ; retirer ferme la porte aux suivants
        // SANS toucher aux inscriptions deja faites.
        Route::post('/classes/{classe}/code-inscription', [LMSClasseEnrolmentCodeController::class, 'store']);
        Route::delete('/classes/{classe}/code-inscription', [LMSClasseEnrolmentCodeController::class, 'destroy']);
    });
