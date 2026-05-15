<?php

/*
|--------------------------------------------------------------------------
| Supradmin Bootstrap Credentials
|--------------------------------------------------------------------------
|
| Credentials used by SupradminSeeder to create the initial platform
| supradmin account on a fresh install. NEVER hardcode values here —
| they must come from the .env file (and the .env file must NEVER be
| committed to git).
|
| If SUPRADMIN_EMAIL or SUPRADMIN_PASSWORD are not set in .env, the
| seeder will refuse to run.
|
| ⚠️  After the first seed in production, rotate the password
|    immediately via:
|
|        php artisan tinker
|        > User::where('email', config('supradmin.email'))->first()
|              ->update(['password' => Hash::make('NOUVEAU_MOT_DE_PASSE')]);
|
| References:
| - PRODUCTION_STANDARDS.md §1.2 — "Aucun secret en plaintext en base"
| - Laravel docs — Configuration: env() must be used only in config files
|
*/

return [

    'email'    => env('SUPRADMIN_EMAIL'),
    'password' => env('SUPRADMIN_PASSWORD'),

];
