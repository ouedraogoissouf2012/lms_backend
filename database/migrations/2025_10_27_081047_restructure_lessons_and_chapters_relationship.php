<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Restructure: Chapter → Lessons TO Lesson → Chapters
     * - Remove chapter_id from lessons (inverse relationship)
     * - Add lesson_id to chapters (new parent relationship)
     * - Add meta-info fields to lessons (prerequis, niveau, objectifs)
     * - Move content fields from lessons to chapters
     * - Add PowerPoint/File conversion support fields
     */
    public function up(): void
    {
        // 1. Drop chapter_id from lessons table (SQLite compatible)
        if (Schema::hasColumn('lessons', 'chapter_id')) {
            Schema::table('lessons', function (Blueprint $table) {
                $table->dropIndex('lessons_chapter_id_index');
                $table->dropForeign(['chapter_id']);
            });

            Schema::table('lessons', function (Blueprint $table) {
                $table->dropColumn('chapter_id');
            });
        }

        // 2. Add new meta-info fields to lessons table
        Schema::table('lessons', function (Blueprint $table) {
            // Pedagogical metadata
            $table->text('prerequis')->nullable()->after('description');
            $table->enum('niveau_difficulte', ['debutant', 'intermediaire', 'avance'])
                  ->default('debutant')
                  ->after('prerequis');
            $table->text('objectifs_pedagogiques')->nullable()->after('niveau_difficulte');
            $table->integer('duree_estimee_minutes')->nullable()->after('objectifs_pedagogiques');
        });

        // 3. Restructure chapters table
        Schema::table('chapters', function (Blueprint $table) {
            // Add lesson_id (new parent relationship)
            $table->unsignedBigInteger('lesson_id')->nullable()->after('matiere_id');
            $table->foreign('lesson_id')->references('id')->on('lessons')->onDelete('cascade');

            // Add content type for chapters
            if (!Schema::hasColumn('chapters', 'content_type')) {
                $table->enum('content_type', [
                    'text', 'video', 'pdf', 'audio',
                    'presentation', 'powerpoint', 'word',
                    'excel', 'link', 'mixed'
                ])->default('text')->after('description');
            }

            // Content fields (moving from lessons to chapters)
            if (!Schema::hasColumn('chapters', 'content')) {
                $table->longText('content')->nullable()->after('content_type');
            }

            // Media URLs
            $table->string('video_url')->nullable()->after('content');
            $table->enum('video_provider', ['youtube', 'vimeo', 'custom'])->nullable()->after('video_url');
            $table->string('pdf_url')->nullable()->after('video_provider');
            $table->string('audio_url')->nullable()->after('pdf_url');
            $table->string('presentation_url')->nullable()->after('audio_url');
            $table->string('external_link')->nullable()->after('presentation_url');

            // PowerPoint/File conversion fields
            $table->string('file_original_path')->nullable()->after('external_link')
                  ->comment('Original uploaded file (.pptx, .docx, etc.)');
            $table->string('file_converted_path')->nullable()->after('file_original_path')
                  ->comment('Converted PDF version');
            $table->json('slides_images')->nullable()->after('file_converted_path')
                  ->comment('Array of PNG image URLs for slides');
            $table->integer('slides_count')->nullable()->after('slides_images')
                  ->comment('Number of slides/pages');
            $table->json('notes_enseignant')->nullable()->after('slides_count')
                  ->comment('Teacher notes per slide');

            // Display options
            $table->boolean('allow_download')->default(true)->after('notes_enseignant');
            $table->boolean('show_slide_numbers')->default(true)->after('allow_download');
            $table->boolean('autoplay_video')->default(false)->after('show_slide_numbers');

            // Order for drag & drop reordering
            if (!Schema::hasColumn('chapters', 'order')) {
                $table->integer('order')->default(0)->after('autoplay_video');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse chapters modifications
        Schema::table('chapters', function (Blueprint $table) {
            // Drop new columns
            $table->dropColumn([
                'lesson_id',
                'video_url', 'video_provider', 'pdf_url', 'audio_url',
                'presentation_url', 'external_link',
                'file_original_path', 'file_converted_path',
                'slides_images', 'slides_count', 'notes_enseignant',
                'allow_download', 'show_slide_numbers', 'autoplay_video'
            ]);
        });

        // Reverse lessons modifications
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn([
                'prerequis',
                'niveau_difficulte',
                'objectifs_pedagogiques',
                'duree_estimee_minutes'
            ]);

            // Restore chapter_id
            $table->unsignedBigInteger('chapter_id')->nullable()->after('enseignant_id');
            $table->foreign('chapter_id')->references('id')->on('chapters')->onDelete('set null');
        });
    }
};
