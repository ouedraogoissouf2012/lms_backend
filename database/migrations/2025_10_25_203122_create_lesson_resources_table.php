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
        Schema::create('lesson_resources', function (Blueprint $table) {
            $table->id();

            // Relation avec la leçon
            $table->unsignedBigInteger('lesson_id')->comment('ID de la leçon');

            // Informations de la ressource
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('type', ['pdf', 'document', 'link', 'video', 'image', 'archive', 'other'])->default('pdf');

            // Fichier ou URL
            $table->string('file_path')->nullable()->comment('Chemin du fichier sur le serveur');
            $table->string('url')->nullable()->comment('URL externe');
            $table->string('mime_type')->nullable();
            $table->bigInteger('file_size')->nullable()->comment('Taille en octets');

            // Métadonnées
            $table->integer('order')->default(0)->comment('Ordre d\'affichage');
            $table->integer('download_count')->default(0)->comment('Nombre de téléchargements');

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('lesson_id');
            $table->index('type');
            $table->index('order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_resources');
    }
};
