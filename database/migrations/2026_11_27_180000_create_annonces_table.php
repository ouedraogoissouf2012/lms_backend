<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1650 : une annonce du personnel à toute une classe est un ENREGISTREMENT. Il dit qui a annoncé quoi, à quelle classe,
 * à combien d'élèves et quand. Chaque élève en reçoit une notification `annonce` qui le désigne (`data.annonce_id`).
 *
 * `classe_id` est l'id LOCAL de la classe, dans les deux types d'école. La suppression d'une classe ou d'un compte
 * n'efface pas une annonce déjà partie : la clé devient nulle, la trace reste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annonces', static function (Blueprint $table): void {
            $table->id();
            // Une école se supprime logiquement (`InstitutionCrudService::softDelete`) : la clé ne s'efface jamais en
            // cascade, comme `training_sessions`.
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('classe_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('titre', 150);
            $table->text('message');
            $table->unsignedInteger('destinataires');
            $table->timestamps();

            $table->index(['institution_id', 'classe_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annonces');
    }
};
