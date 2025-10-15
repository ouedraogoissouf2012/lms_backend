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
        Schema::table('users', function (Blueprint $table) {
            // ID utilisateur KLASSCI (unique)
            $table->unsignedBigInteger('klassci_id')->nullable()->unique()->after('id');

            // Rôle utilisateur (enseignant, etudiant, coordinateur, admin)
            $table->string('role')->default('student')->after('email');

            // Token KLASSCI (pour appels API au nom de l'utilisateur)
            $table->text('klassci_token')->nullable()->after('password');

            // Données complètes KLASSCI (JSON)
            $table->json('klassci_data')->nullable()->after('klassci_token');

            // Date dernière synchronisation avec KLASSCI
            $table->timestamp('last_klassci_sync')->nullable()->after('klassci_data');

            // Index pour performance
            $table->index('klassci_id');
            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['klassci_id']);
            $table->dropIndex(['role']);
            $table->dropColumn([
                'klassci_id',
                'role',
                'klassci_token',
                'klassci_data',
                'last_klassci_sync',
            ]);
        });
    }
};
