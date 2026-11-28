<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1698 (C2) — la dispense d'un élève pour une évaluation : sa note sort de tous les calculs
 * (Mes notes, bilan, rang, carnet).
 *
 * L'élève est désigné comme ses copies (`evaluation_submissions`) : par son compte LOCAL
 * (`student_id`), ou, pour un élève du roster KLASSCI sans compte, par son identité KLASSCI.
 * Une dispense par évaluation et par élève : deux uniques, l'un par compte, l'autre par identité
 * KLASSCI ; plusieurs NULL n'y entrent pas en collision (MySQL, SQLite).
 *
 * Table neuve : aucune donnée existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispenses_d_evaluation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('evaluation_id')->constrained('evaluations')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('klassci_etudiant_id')->nullable();
            // L'auteur de la dispense ; un compte supprimé ne l'efface pas.
            $table->foreignId('accordee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif', 255)->nullable();
            $table->timestamps();

            $table->unique(['evaluation_id', 'student_id'], 'dispenses_evaluation_compte_unique');
            $table->unique(['evaluation_id', 'klassci_etudiant_id'], 'dispenses_evaluation_klassci_unique');
            $table->index('institution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispenses_d_evaluation');
    }
};
