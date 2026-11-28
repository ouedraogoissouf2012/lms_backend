<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1678 — la réclamation d'une note : l'élève conteste la note d'une de ses copies, l'enseignant
 * donne son avis, la gestion tranche. Circuit à états nommés : `deposee` → `avis_donne` →
 * `tranchee` ; une seule réclamation ouverte par copie (vérifiée par le service).
 *
 * Table neuve : aucune donnée existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reclamations_de_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('evaluation_id')->constrained('evaluations')->cascadeOnDelete();
            $table->foreignId('evaluation_submission_id')->constrained('evaluation_submissions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->text('motif');
            $table->string('statut', 16)->default('deposee');
            $table->decimal('note_contestee', 5, 2)->nullable();
            $table->string('avis', 12)->nullable();
            $table->text('avis_commentaire')->nullable();
            $table->decimal('note_proposee', 5, 2)->nullable();
            $table->foreignId('avis_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('avis_le')->nullable();
            $table->string('decision', 12)->nullable();
            $table->decimal('note_decidee', 5, 2)->nullable();
            $table->text('decision_commentaire')->nullable();
            $table->foreignId('decision_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decision_le')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'statut']);
            $table->index('evaluation_submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclamations_de_notes');
    }
};
