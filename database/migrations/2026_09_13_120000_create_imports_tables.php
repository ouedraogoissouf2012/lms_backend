<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('user_id');
            $table->string('path');
            $table->string('original_name');
            $table->string('status');
            $table->unsignedInteger('ok_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamps();
            $table->foreign('institution_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_id');
            $table->unsignedInteger('line');
            $table->string('status');
            $table->string('code')->nullable();
            $table->string('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->foreign('import_id')->references('id')->on('imports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('imports');
    }
};
