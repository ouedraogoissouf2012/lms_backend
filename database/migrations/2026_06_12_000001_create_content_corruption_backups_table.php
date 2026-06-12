<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table de backup pour la correction du champ `content` corrompu (#231).
 *
 * Le bug `$this->content` (corps HTTP brut, cf. #212/PR #230) a écrasé le
 * champ `content` de forum_topics / forum_posts / lessons / chapters avec le
 * JSON entier du payload. La commande `content:fix-corruption` extrait le vrai
 * contenu, mais sauvegarde TOUJOURS la valeur d'origine ici avant écrasement —
 * réversibilité totale en cas de faux positif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_corruption_backups', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('row_id');
            $table->longText('original_content');
            $table->longText('corrected_content');
            $table->timestamp('fixed_at')->useCurrent();

            // Empêche un double-traitement de la même row (idempotence).
            $table->unique(['table_name', 'row_id']);
            $table->index('fixed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_corruption_backups');
    }
};
