<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1661, PR 1 : le catalogue des pièces à fournir d'une école autonome. Le `code` est unique dans l'école : c'est lui
 * qu'attend une réserve d'inscription (#1658). Une pièce se désactive, elle ne se supprime pas (ses documents la
 * désignent, PR 2). Elle vaut pour toute l'école ; le ciblage par filière et niveau viendra avec #1648.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieces_a_fournir', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('libelle', 150);
            $table->string('consigne', 500)->nullable();
            $table->string('forme', 16);
            $table->unsignedTinyInteger('quantite')->default(1);
            $table->string('portee', 24);
            $table->unsignedSmallInteger('validite_jours')->nullable();
            $table->boolean('obligatoire')->default(true);
            $table->boolean('en_service')->default(true);
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_a_fournir');
    }
};
