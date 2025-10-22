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
        Schema::table('seances', function (Blueprint $table) {
            // Cache des données KLASSCI pour optimisation
            $table->string('enseignant_nom')->nullable()->after('klassci_enseignant_id')
                ->comment('Nom enseignant (cache depuis KLASSCI ou user connecté)');

            $table->string('matiere_nom')->nullable()->after('klassci_matiere_id')
                ->comment('Nom matière (cache depuis KLASSCI)');

            $table->integer('classe_effectif')->nullable()->after('klassci_classe_id')
                ->comment('Nombre étudiants dans la classe');

            // États de la visio (workflow enseignant)
            $table->enum('visio_status', ['programmee', 'active', 'terminee'])
                ->nullable()
                ->after('visio_active')
                ->comment('État workflow: programmee -> active -> terminee');

            // Compteur participants
            $table->integer('visio_participants_count')->default(0)
                ->after('visio_ended_at')
                ->comment('Nombre participants actuellement connectés');

            // Index pour performance
            $table->index('visio_status');
            $table->index(['visio_enabled', 'visio_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table) {
            $table->dropColumn([
                'enseignant_nom',
                'matiere_nom',
                'classe_effectif',
                'visio_status',
                'visio_participants_count',
            ]);
        });
    }
};
