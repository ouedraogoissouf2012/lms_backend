<?php

use Illuminate\Support\Facades\Route;

// #1868 M1 (falsification volontaire) : capture `lms/classes/etablissement` selon l'ordre de chargement.
Route::get('lms/classes/{piege}', static fn () => response()->noContent());
