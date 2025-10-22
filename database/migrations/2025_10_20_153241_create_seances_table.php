<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('seances', function (Blueprint $table) {
            $table->id();

            // Références KLASSCI uniquement (les données séance viennent de KLASSCI)
            $table->unsignedBigInteger('klassci_seance_id')->unique()->comment('ID de la séance dans KLASSCI');
            $table->unsignedBigInteger('klassci_matiere_id')->nullable()->comment('Copie pour faciliter les joins');
            $table->unsignedBigInteger('klassci_classe_id')->nullable()->comment('Copie pour faciliter les joins');
            $table->unsignedBigInteger('klassci_enseignant_id')->nullable()->comment('Copie pour faciliter les joins');

            // Visioconférence (géré par LMS uniquement)
            $table->boolean('visio_enabled')->default(false);
            $table->enum('visio_type', ['jitsi', 'zoom', 'teams', 'bbb'])->nullable();
            $table->string('visio_room_id')->nullable();
            $table->boolean('visio_active')->default(false);
            $table->timestamp('visio_started_at')->nullable();
            $table->timestamp('visio_ended_at')->nullable();

            // Gestion
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('klassci_seance_id');
            $table->index('klassci_classe_id');
            $table->index('klassci_enseignant_id');
            $table->index(['visio_enabled', 'visio_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seances');
    }
};
