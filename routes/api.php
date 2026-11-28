<?php

/*
|--------------------------------------------------------------------------
| API Routes — LMS KLASSCI Backend
|--------------------------------------------------------------------------
| Découpé par domaine dans routes/api/*.php (§1.1 ≤300 lignes). Ce fichier est
| chargé DEUX fois par bootstrap/app.php (/api et /api/v1, versioning #217) —
| d'où `require` (PAS require_once) : les deux montages doivent réenregistrer
| les routes.
*/

use Illuminate\Support\Facades\Route;

// La porte de TOUTES les routes API : un compte sans établissement n'atteint
// que les routes marquées `plateforme` (EnsureSchoolMemberOrPlatformRoute).
// Une route nouvelle, ajoutée ici ou dans un fichier requis, naît fermée.
//
// #1868 : CHAQUE fichier de routes/api/ est chargé, par ordre alphabétique. Un
// domaine neuf est un fichier neuf, sans ligne à ajouter ici : plus de liste
// partagée qui rouvre les conflits des PR. L'ordre des fichiers ne compte pas, et
// la garde `LOrdreDesFichiersDeRoutesNeCompteTest` le maintient : une route d'un
// fichier qui en capturerait une d'un autre selon l'ordre de chargement rougit.
Route::middleware('etablissement.requis')->group(function (): void {
    $fichiersDeRoutes = glob(__DIR__.'/api/*.php') ?: [];
    sort($fichiersDeRoutes);
    foreach ($fichiersDeRoutes as $fichierDeRoutes) {
        require $fichierDeRoutes;
    }
});
