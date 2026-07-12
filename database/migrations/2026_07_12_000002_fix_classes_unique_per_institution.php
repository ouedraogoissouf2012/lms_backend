<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table): void {
            $table->dropUnique('classes_klassci_id_unique');
            $table->unique(
                ['klassci_id', 'institution_id'],
                'classes_klassci_institution_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table): void {
            $table->dropUnique('classes_klassci_institution_unique');
            $table->unique('klassci_id', 'classes_klassci_id_unique');
        });
    }
};
