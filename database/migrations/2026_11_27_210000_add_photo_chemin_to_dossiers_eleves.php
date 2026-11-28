<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1657, PR 2 : le chemin de la photo de l'élève, sur le disque PRIVÉ (`local`). L'image est ré-encodée par le
 * serveur ; le fichier d'origine n'est jamais gardé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossiers_eleves', static function (Blueprint $table): void {
            $table->string('photo_chemin', 255)->nullable()->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('dossiers_eleves', static function (Blueprint $table): void {
            $table->dropColumn('photo_chemin');
        });
    }
};
