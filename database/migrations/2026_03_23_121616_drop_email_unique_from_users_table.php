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
            // Le UNIQUE sur email seul est incompatible avec le multi-tenant :
            // deux écoles différentes peuvent avoir un utilisateur avec le même email.
            // L'unicité est garantie par le composite (klassci_id, institution_id).
            $table->dropUnique(['email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }
};
