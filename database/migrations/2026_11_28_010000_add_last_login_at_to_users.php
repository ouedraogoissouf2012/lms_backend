<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1704 : la dernière connexion d'un compte, écrite par la connexion réussie dans les deux volets
 * (`DerniereConnexion`).
 *
 * Reprise : un jeton Sanctum n'est créé que par une connexion (ou son renouvellement), et un jeton
 * vit 7 jours. Le plus récent `created_at` des jetons du compte est donc une connexion réelle : sans
 * cette reprise, tout élève connecté depuis moins d'une semaine serait « sans connexion » au
 * lendemain du déploiement. Un compte sans jeton reste nul : l'alerte dit « aucune connexion
 * enregistrée », sans date fabriquée. Le journal d'audit n'est pas lu (il se coupe et se purge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('last_klassci_sync');
        });

        $types = array_values(array_unique([User::class, (new User)->getMorphClass()]));
        DB::update(
            'UPDATE users SET last_login_at = (SELECT MAX(pat.created_at) FROM personal_access_tokens pat'
            .' WHERE pat.tokenable_id = users.id AND pat.tokenable_type IN ('.implode(', ', array_fill(0, count($types), '?')).'))'
            .' WHERE last_login_at IS NULL',
            $types,
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
