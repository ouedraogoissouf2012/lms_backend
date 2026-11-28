<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1658, PR 1 : la validation d'une inscription, À CÔTÉ de son statut. Le `statut` garde seul l'ACCÈS (actif), que
 * lisent tous les autres services ; `validation` dit la décision de l'école (en_attente, validee, annulee). Une ligne
 * existante, ou synchronisée de KLASSCI, garde `validation` nulle : rien n'y est décidé dans le LMS.
 *
 * Avec le réglage `institutions.inscriptions_a_valider`, une inscription par code naît inactive et en attente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classe_etudiant', static function (Blueprint $table): void {
            $table->string('validation', 16)->nullable();
            $table->timestamp('valide_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('annule_le')->nullable();
            $table->foreignId('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif_annulation', 500)->nullable();
            $table->string('reserve_condition', 255)->nullable();
            $table->string('reserve_piece', 100)->nullable();
            $table->timestamp('reserve_levee_le')->nullable();
            $table->foreignId('reserve_levee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('redoublant')->nullable();
            $table->string('etablissement_origine', 150)->nullable();
            $table->string('affectation', 16)->nullable();
        });

        Schema::table('institutions', static function (Blueprint $table): void {
            $table->boolean('inscriptions_a_valider')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('institutions', static function (Blueprint $table): void {
            $table->dropColumn('inscriptions_a_valider');
        });

        Schema::table('classe_etudiant', static function (Blueprint $table): void {
            $table->dropForeign(['valide_par']);
            $table->dropForeign(['annule_par']);
            $table->dropForeign(['reserve_levee_par']);
            $table->dropColumn([
                'validation', 'valide_le', 'valide_par', 'annule_le', 'annule_par', 'motif_annulation',
                'reserve_condition', 'reserve_piece', 'reserve_levee_le', 'reserve_levee_par',
                'redoublant', 'etablissement_origine', 'affectation',
            ]);
        });
    }
};
