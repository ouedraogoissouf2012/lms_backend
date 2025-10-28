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
        Schema::table('lessons', function (Blueprint $table) {
            // Relation avec les chapitres
            $table->unsignedBigInteger('chapter_id')->nullable()->after('enseignant_id')->comment('ID du chapitre parent');
            $table->index('chapter_id');

            // Types de contenus multimédias
            $table->enum('content_type', ['text', 'video', 'pdf', 'audio', 'presentation', 'link', 'mixed'])
                ->default('text')
                ->after('content')
                ->comment('Type de contenu principal');

            $table->string('video_url')->nullable()->after('content_type')->comment('URL de la vidéo');
            $table->enum('video_provider', ['youtube', 'vimeo', 'local', 'other'])->nullable()->after('video_url');
            $table->string('pdf_url')->nullable()->after('video_provider')->comment('URL du PDF');
            $table->string('audio_url')->nullable()->after('pdf_url')->comment('URL de l\'audio');
            $table->string('presentation_url')->nullable()->after('audio_url')->comment('URL de la présentation');
            $table->string('external_link')->nullable()->after('presentation_url')->comment('Lien externe');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['chapter_id']);
            $table->dropColumn([
                'chapter_id',
                'content_type',
                'video_url',
                'video_provider',
                'pdf_url',
                'audio_url',
                'presentation_url',
                'external_link'
            ]);
        });
    }
};
