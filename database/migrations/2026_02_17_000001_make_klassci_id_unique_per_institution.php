<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Supprimer l'ancien index unique sur klassci_id seul
            $table->dropUnique(['klassci_id']);

            // Créer un index unique composé (klassci_id + institution_id)
            // Permet à deux institutions différentes d'avoir le même klassci_id
            $table->unique(['klassci_id', 'institution_id'], 'users_klassci_id_institution_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_klassci_id_institution_id_unique');
            $table->unique('klassci_id');
        });
    }
};
