<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->after('evaluation_id')->constrained('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table) {
            $table->dropForeignKeyIfExists(['student_id']);
            $table->dropColumn('student_id');
        });
    }
};
