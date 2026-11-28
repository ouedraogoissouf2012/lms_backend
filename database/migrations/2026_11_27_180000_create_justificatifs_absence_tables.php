<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1681 — l'élève justifie une absence, l'école tranche (ARRÊT 1 validé le 27/11).
 *
 * - `justificatifs_absence` : une table par école (déclarée dans `tenancy.institution_scoped_tables`), clé de l'école
 *   en RESTRICT comme toute table déclarée. La pièce, facultative, a son propre stockage (disque privé) : la table
 *   n'en garde que le chemin, le nom, le type et la taille. `du` / `au` disent la plage déposée, pour l'affichage.
 * - `justificatif_absence_seance` : les séances VISÉES, résolues au dépôt (séances nommées ou plage) ; la décision ne
 *   lit qu'elles. Cascade : une séance ou un justificatif supprimé emporte ses liens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('justificatifs_absence', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('eleve_id')->constrained('users')->cascadeOnDelete();
            $table->text('motif');
            $table->date('du')->nullable();
            $table->date('au')->nullable();
            $table->string('piece_chemin')->nullable();
            $table->string('piece_nom')->nullable();
            $table->string('piece_mime', 100)->nullable();
            $table->unsignedInteger('piece_taille')->nullable();
            $table->string('etat', 16)->default('en_attente');
            $table->foreignId('decide_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'etat', 'created_at']);
            $table->index(['institution_id', 'eleve_id']);
        });

        Schema::create('justificatif_absence_seance', static function (Blueprint $table): void {
            $table->foreignId('justificatif_id')->constrained('justificatifs_absence')->cascadeOnDelete();
            $table->foreignId('seance_id')->constrained('seances')->cascadeOnDelete();

            $table->primary(['justificatif_id', 'seance_id']);
            $table->index('seance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('justificatif_absence_seance');
        Schema::dropIfExists('justificatifs_absence');
    }
};
