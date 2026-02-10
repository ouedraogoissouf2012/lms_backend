<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "=== TOUS LES UTILISATEURS ===" . PHP_EOL . PHP_EOL;

$users = User::all();
foreach ($users as $user) {
    echo "ID: {$user->id} | Klassci ID: {$user->klassci_id} | Role: {$user->role} | {$user->name} | {$user->email}" . PHP_EOL;
}

echo PHP_EOL . "=== ÉTUDIANTS DANS LA TABLE PIVOT classe_etudiant ===" . PHP_EOL . PHP_EOL;

$pivots = DB::table('classe_etudiant')
    ->join('users', 'classe_etudiant.user_id', '=', 'users.id')
    ->join('classes', 'classe_etudiant.classe_id', '=', 'classes.id')
    ->select('classe_etudiant.*', 'users.name', 'users.role', 'users.email', 'classes.libelle as classe_nom')
    ->get();

foreach ($pivots as $pivot) {
    echo "User ID: {$pivot->user_id} | Role: {$pivot->role} | {$pivot->name} | Classe: {$pivot->classe_nom} | Statut: {$pivot->statut}" . PHP_EOL;
}
