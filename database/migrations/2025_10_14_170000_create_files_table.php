<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Table files
 *
 * Gestion des fichiers uploadés (PDF, images, vidéos, documents)
 * Liés aux cours (lessons), forum, ou autres entités
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();

            // Utilisateur qui a uploadé le fichier
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Entité liée (polymorphique)
            $table->nullableMorphs('fileable'); // fileable_id + fileable_type

            // Informations du fichier
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('path'); // Chemin relatif dans storage
            $table->string('mime_type');
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes'); // Taille en octets

            // Métadonnées
            $table->string('type')->default('document'); // document, image, video, audio, other
            $table->string('category')->nullable(); // course_material, assignment, forum_attachment, etc.
            $table->text('description')->nullable();

            // Statistiques
            $table->unsignedInteger('downloads_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            // Sécurité et validation
            $table->boolean('is_public')->default(false); // Accessible sans auth?
            $table->boolean('is_validated')->default(true); // Pour modération si besoin
            $table->string('virus_scan_status')->default('pending'); // pending, clean, infected

            $table->timestamps();
            $table->softDeletes();

            // Index pour performance
            $table->index(['fileable_type', 'fileable_id']);
            $table->index('user_id');
            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
