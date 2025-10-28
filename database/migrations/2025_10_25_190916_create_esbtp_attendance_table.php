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
        Schema::create('esbtp_attendance', function (Blueprint $table) {
            $table->id();

            // Références
            $table->unsignedBigInteger('seance_id'); // ID de la séance
            $table->unsignedBigInteger('user_id'); // ID utilisateur LMS local
            $table->unsignedBigInteger('klassci_etudiant_id')->nullable(); // ID étudiant dans KLASSCI

            // Informations participant (cache pour éviter requêtes KLASSCI)
            $table->string('nom')->nullable();
            $table->string('prenom')->nullable();
            $table->string('email')->nullable();

            // Tracking participation
            $table->timestamp('joined_at')->nullable(); // Heure de connexion
            $table->timestamp('left_at')->nullable(); // Heure de déconnexion (pour plus tard)
            $table->integer('duration_minutes')->nullable(); // Durée en minutes (pour plus tard)

            // Métadonnées
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_validated')->default(true); // Si présence validée par enseignant

            $table->timestamps();

            // Index pour performance
            $table->index('seance_id');
            $table->index('user_id');
            $table->index('klassci_etudiant_id');
            $table->index('joined_at');

            // Contrainte: un étudiant ne peut rejoindre qu'une seule fois par séance
            $table->unique(['seance_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esbtp_attendance');
    }
};
