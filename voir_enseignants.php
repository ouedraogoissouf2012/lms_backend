<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;

$coordinateur = User::where('role', 'coordinateur')->first();

$response = Http::withoutVerifying()->withHeaders([
    'Authorization' => 'Bearer ' . $coordinateur->klassci_token,
    'Accept' => 'application/json',
])->get('https://presentation.klassci.com/api/lms/enseignants');

$data = $response->json();
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n";
