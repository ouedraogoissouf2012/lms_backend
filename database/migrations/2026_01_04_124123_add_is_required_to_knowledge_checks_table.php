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
        Schema::table('knowledge_checks', function (Blueprint $table) {
            // Quiz obligatoire pour passer au chapitre suivant
            $table->boolean('is_required')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_checks', function (Blueprint $table) {
            $table->dropColumn('is_required');
        });
    }
};
