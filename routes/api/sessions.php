<?php

declare(strict_types=1);

use App\Http\Controllers\API\LMS\LMSClasseArchiveController;
use App\Http\Controllers\API\LMS\LMSClasseCapaciteController;
use App\Http\Controllers\API\LMS\LMSClasseEleveController;
use App\Http\Controllers\API\LMS\LMSClasseEleveMutationController;
use App\Http\Controllers\API\LMS\LMSClasseEnrolmentCodeController;
use App\Http\Controllers\API\LMS\LMSClasseMatiereController;
use App\Http\Controllers\API\LMS\LMSClassePeriodeController;
use App\Http\Controllers\API\LMS\LMSClasseWriteController;
use App\Http\Controllers\API\LMS\LMSMatiereWriteController;
use App\Http\Controllers\API\LMS\TrainingSessionController;
use App\Http\Controllers\API\LMS\TrainingSessionEditionController;
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
// La garde `gestion` (`isManager()`) : organiser une formation est un acte de
// gestion. Un enseignant anime, il ne date pas la session ; un etudiant la suit.
Route::middleware(['auth:sanctum', 'klassci.sync', 'gestion'])
    ->group(function () {
        Route::post('/programs', [TrainingSessionController::class, 'storeProgram']);
        // Lire ses Programmes pour en choisir un (#1206) : le front faisait saisir
        // le NUMERO du programme, faute de pouvoir les lister.
        Route::get('/programs', [TrainingSessionController::class, 'indexPrograms']);
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

        // Modifier une Periode, supprimer un brouillon, designer la periode EN COURS (#1651).
        // `en-cours` est un geste NOMME, comme les transitions : il deplace une marque unique
        // par ecole, que la base garde (unicite, et seulement une periode publiee).
        Route::put('/training-sessions/{trainingSession}', [TrainingSessionEditionController::class, 'update']);
        Route::delete('/training-sessions/{trainingSession}', [TrainingSessionEditionController::class, 'destroy']);
        Route::post('/training-sessions/{trainingSession}/en-cours', [TrainingSessionEditionController::class, 'enCours']);

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
        // La modifier (#1652) : une classe LOCALE de son ecole ; son code y est unique.
        Route::patch('/classes/{classe}', [LMSClasseWriteController::class, 'update'])->whereNumber('classe');
        // Sa collection, une LECTURE, vit dans `routes/api/classes.php` (#924).

        // Creer une matiere SANS KLASSCI (#797, #848). Second des trois verrous
        // de l ADR : la classe n exigeait que l ecrivain, la matiere exigeait
        // d abord de lever `matieres.klassci_id` NOT NULL. Sans elle, une ecole
        // autonome avait des classes mais rien a y enseigner.
        Route::post('/matieres', [LMSMatiereWriteController::class, 'store']);
        // La modifier (#1653) : une matiere LOCALE de son ecole ; son code y est unique.
        Route::patch('/matieres/{matiere}', [LMSMatiereWriteController::class, 'update'])->whereNumber('matiere');

        // Composer le programme d une classe (#848) : rattacher une matiere, et
        // y designer un formateur. Ecrit `classe_matiere`, le pivot LOCAL que
        // LocalEnrollmentSource:35-40 lit deja — `matiere_enseignant` n est pas
        // touchee, c est le miroir KLASSCI.
        Route::post('/classes/{classe}/matieres', [LMSClasseMatiereController::class, 'store']);
        // Le modifier (formateur, coefficient de classe) et en retirer une matiere (#1653) : le
        // retrait est refuse tant qu une trace pedagogique de l ecole renvoie au couple.
        Route::patch('/classes/{classe}/matieres/{matiere}', [LMSClasseMatiereController::class, 'update'])
            ->whereNumber(['classe', 'matiere']);
        Route::delete('/classes/{classe}/matieres/{matiere}', [LMSClasseMatiereController::class, 'destroy'])
            ->whereNumber(['classe', 'matiere']);

        // Le code d inscription d une classe (#846, ADR-803-03). Rendu EN CLAIR
        // — il circule par telephone et se reaffiche, contrairement au lien
        // d activation qui, lui, est hache et a usage unique.
        //
        // Emettre remplace le precedent ; retirer ferme la porte aux suivants
        // SANS toucher aux inscriptions deja faites.
        Route::post('/classes/{classe}/code-inscription', [LMSClasseEnrolmentCodeController::class, 'store']);
        Route::delete('/classes/{classe}/code-inscription', [LMSClasseEnrolmentCodeController::class, 'destroy']);

        // Rattacher une classe a une Periode, ou l en detacher (#1208). Un geste
        // NOMME : il rend reelles les regles deja ecrites (une Periode close ferme
        // les codes de ses classes, #897 ; `is_active`), sans ouvrir d autre champ.
        Route::put('/classes/{classe}/periode', [LMSClassePeriodeController::class, 'update'])
            ->whereNumber('classe');

        // Regler la capacite d une classe (#1358) : celle que l inscription par code
        // respecte (#1115). Un geste NOMME, comme la Periode ; null ou 0 = sans limite.
        Route::put('/classes/{classe}/capacite', [LMSClasseCapaciteController::class, 'update'])
            ->whereNumber('classe');

        // Archiver une classe, ou la desarchiver (#1652) : deux gestes NOMMES. Archiver ferme
        // son code et refuse toute nouvelle inscription ; ses eleves et ses traces demeurent.
        Route::post('/classes/{classe}/archiver', [LMSClasseArchiveController::class, 'archiver'])
            ->whereNumber('classe');
        Route::post('/classes/{classe}/desarchiver', [LMSClasseArchiveController::class, 'desarchiver'])
            ->whereNumber('classe');

        // Changer un eleve de classe dans son ecole (#1652) : en une transaction, l ancienne
        // adhesion se ferme (inactif, datee, motif mutation) et la nouvelle s ouvre.
        Route::post('/classes/{classe}/eleves/{eleve}/muter', [LMSClasseEleveMutationController::class, 'store'])
            ->whereNumber(['classe', 'eleve']);

        // Inscrire un eleve existant dans une classe, ou l en retirer (#1619) : l entree decidee
        // par l ecole (memes refus que la mutation) ; le retrait ferme l adhesion, le dossier demeure.
        Route::post('/classes/{classe}/eleves', [LMSClasseEleveController::class, 'store'])->whereNumber('classe');
        Route::delete('/classes/{classe}/eleves/{eleve}', [LMSClasseEleveController::class, 'destroy'])
            ->whereNumber(['classe', 'eleve']);
    });
