<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1663, PR 1 : les règles de passage d'une école autonome, et les décisions de passage de ses élèves.
 *
 * - `regles_de_passage` : la ligne de l'école (`classe_id` nul) et, au plus une par classe, sa surcharge. Une valeur
 *   nulle hérite. La moyenne de passage de l'ÉCOLE n'y est pas : c'est `institutions.settings.seuil_reussite`, la seule
 *   source, que lit aussi le bilan de classe ; seule une classe la surcharge ici.
 * - `decisions_de_passage` : la décision du personnel pour un élève d'une classe (une par élève et par classe), avec la
 *   proposition qu'il a corrigée et son motif. Elle suit l'élève et la classe (cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regles_de_passage', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('classe_id')->nullable()->constrained('classes')->cascadeOnDelete();
            // La clé d'unicité NON NULLE : l'id de la classe, 0 pour la ligne de l'école. Un index unique admet plusieurs
            // NULL : sur `classe_id`, deux écritures simultanées créeraient deux règles d'école.
            $table->unsignedBigInteger('classe_cle')->default(0);
            $table->decimal('moyenne_passage', 4, 2)->nullable();
            $table->decimal('moyenne_rattrapage', 4, 2)->nullable();
            $table->unsignedTinyInteger('max_matieres_rattrapage')->nullable();
            $table->boolean('redoublement_autorise')->nullable();
            $table->unsignedTinyInteger('max_redoublements')->nullable();
            $table->timestamps();
            $table->unique(['institution_id', 'classe_cle']);
        });

        Schema::create('decisions_de_passage', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('proposition', 16)->nullable();
            $table->string('decision', 16);
            $table->string('motif', 500);
            $table->foreignId('decide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['classe_id', 'user_id']);
            $table->index(['user_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions_de_passage');
        Schema::dropIfExists('regles_de_passage');
    }
};
