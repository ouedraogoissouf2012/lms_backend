<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1662 — les documents de scolarité émis par une école (certificat de scolarité, attestation de fréquentation).
 *
 * - Un numéro par école, par type et par année (`CS-2026-0001`), unique dans l'école ; tiré sous le verrou de l'école.
 * - Un code de vérification unique (12 caractères) : la page publique de vérification le lit.
 * - `contenu` : l'instantané de ce qui a été imprimé ; un document émis ne change plus, même si l'élève change de
 *   classe ensuite.
 * - `institution_id` et `eleve_id` en RESTRICT : un document émis est une pièce, il ne disparaît pas avec son sujet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('documents_emis')) {
            return;
        }

        Schema::create('documents_emis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('eleve_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 40);
            $table->string('numero', 20);
            $table->string('code_verification', 12)->unique();
            $table->json('contenu');
            $table->foreignId('emis_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['institution_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_emis');
    }
};
