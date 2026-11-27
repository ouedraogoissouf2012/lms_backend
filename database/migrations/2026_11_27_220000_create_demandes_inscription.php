<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1659 : la demande d'inscription qu'une famille dépose auprès d'une école autonome, et son traitement.
 *
 * Seuls les champs utiles (loi 2013-450). Le consentement est horodaté au dépôt. Une demande décidée est purgée 90 jours
 * après sa décision, une demande jamais traitée 365 jours après son dépôt (`admissions:purge`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_inscription', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('nom', 100);
            $table->string('prenoms', 150);
            $table->date('date_naissance')->nullable();
            $table->char('genre', 1)->nullable();
            $table->string('declarant_nom', 150);
            $table->string('declarant_relation', 16);
            $table->string('declarant_telephone', 30)->nullable();
            $table->string('declarant_courriel', 255)->nullable();
            $table->string('classe_souhaitee', 100)->nullable();
            $table->string('diplome_entree', 100)->nullable();
            $table->timestamp('consentement_le');
            $table->string('etat', 16)->default('recue');
            $table->string('motif_rejet', 500)->nullable();
            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_le')->nullable();
            $table->foreignId('eleve_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['institution_id', 'etat', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_inscription');
    }
};
