<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1702 (D2) — la `presentation` d'une copie : les questions POSÉES à l'élève, dans l'ordre
 * affiché, écrites à son démarrage et jamais recalculées. Le mélange est ainsi figé par tentative,
 * et le tirage (D3) reproductible à la correction. Nulle pour une copie d'avant cette colonne :
 * elle garde toutes les questions de l'évaluation, dans leur ordre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table) {
            $table->json('presentation')->nullable()->after('answers');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table) {
            $table->dropColumn('presentation');
        });
    }
};
