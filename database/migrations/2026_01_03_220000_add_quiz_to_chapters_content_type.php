<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Ajoute "quiz" comme type de contenu valide pour les chapitres
     */
    public function up(): void
    {
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            // SQLite: Désactiver les contraintes, recréer la table
            DB::statement('PRAGMA foreign_keys=off;');

            // Créer une table temporaire avec TOUTES les colonnes existantes + quiz ajouté au CHECK
            DB::statement("
                CREATE TABLE chapters_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    matiere_id INTEGER,
                    enseignant_id INTEGER,
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    \"order\" INTEGER DEFAULT 0,
                    duration_minutes INTEGER,
                    created_at DATETIME,
                    updated_at DATETIME,
                    deleted_at DATETIME,
                    lesson_id INTEGER,
                    content_type VARCHAR(50) DEFAULT 'text' CHECK(content_type IN ('text', 'video', 'pdf', 'audio', 'presentation', 'powerpoint', 'word', 'excel', 'link', 'mixed', 'quiz')),
                    content TEXT,
                    video_url VARCHAR(255),
                    video_provider VARCHAR(50),
                    pdf_url VARCHAR(255),
                    audio_url VARCHAR(255),
                    presentation_url VARCHAR(255),
                    external_link VARCHAR(255),
                    file_original_path VARCHAR(255),
                    file_converted_path VARCHAR(255),
                    slides_images TEXT,
                    slides_count INTEGER,
                    notes_enseignant TEXT,
                    allow_download INTEGER DEFAULT 1,
                    show_slide_numbers INTEGER DEFAULT 1,
                    autoplay_video INTEGER DEFAULT 0,
                    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
                    FOREIGN KEY (matiere_id) REFERENCES matieres(id) ON DELETE CASCADE,
                    FOREIGN KEY (enseignant_id) REFERENCES users(id) ON DELETE SET NULL
                )
            ");

            // Copier les données
            DB::statement("
                INSERT INTO chapters_new (
                    id, matiere_id, enseignant_id, title, description, \"order\", duration_minutes,
                    created_at, updated_at, deleted_at, lesson_id, content_type, content,
                    video_url, video_provider, pdf_url, audio_url, presentation_url, external_link,
                    file_original_path, file_converted_path, slides_images, slides_count, notes_enseignant,
                    allow_download, show_slide_numbers, autoplay_video
                )
                SELECT
                    id, matiere_id, enseignant_id, title, description, \"order\", duration_minutes,
                    created_at, updated_at, deleted_at, lesson_id, content_type, content,
                    video_url, video_provider, pdf_url, audio_url, presentation_url, external_link,
                    file_original_path, file_converted_path, slides_images, slides_count, notes_enseignant,
                    allow_download, show_slide_numbers, autoplay_video
                FROM chapters
            ");

            // Supprimer l'ancienne table
            DB::statement("DROP TABLE chapters");

            // Renommer la nouvelle table
            DB::statement("ALTER TABLE chapters_new RENAME TO chapters");

            // Recréer les index
            DB::statement("CREATE INDEX IF NOT EXISTS chapters_lesson_id_index ON chapters(lesson_id)");
            DB::statement("CREATE INDEX IF NOT EXISTS chapters_matiere_id_index ON chapters(matiere_id)");

            DB::statement('PRAGMA foreign_keys=on;');
        } else if ($connection === 'mysql') {
            // MySQL: Modifier directement la colonne
            DB::statement("
                ALTER TABLE chapters
                MODIFY COLUMN content_type ENUM('text', 'video', 'pdf', 'audio', 'presentation', 'powerpoint', 'word', 'excel', 'link', 'mixed', 'quiz')
                DEFAULT 'text'
            ");
        } else if ($connection === 'pgsql') {
            // PostgreSQL: Ajouter la valeur à la contrainte CHECK existante
            DB::statement("
                ALTER TABLE chapters
                DROP CONSTRAINT IF EXISTS chapters_content_type_check
            ");

            DB::statement("
                ALTER TABLE chapters
                ADD CONSTRAINT chapters_content_type_check
                CHECK (content_type IN ('text', 'video', 'pdf', 'audio', 'presentation', 'powerpoint', 'word', 'excel', 'link', 'mixed', 'quiz'))
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            DB::table('chapters')->where('content_type', 'quiz')->update(['content_type' => 'text']);
        } else if ($connection === 'mysql') {
            DB::table('chapters')->where('content_type', 'quiz')->update(['content_type' => 'text']);

            DB::statement("
                ALTER TABLE chapters
                MODIFY COLUMN content_type ENUM('text', 'video', 'pdf', 'audio', 'presentation', 'powerpoint', 'word', 'excel', 'link', 'mixed')
                DEFAULT 'text'
            ");
        } else if ($connection === 'pgsql') {
            DB::table('chapters')->where('content_type', 'quiz')->update(['content_type' => 'text']);

            DB::statement("
                ALTER TABLE chapters
                DROP CONSTRAINT IF EXISTS chapters_content_type_check
            ");

            DB::statement("
                ALTER TABLE chapters
                ADD CONSTRAINT chapters_content_type_check
                CHECK (content_type IN ('text', 'video', 'pdf', 'audio', 'presentation', 'powerpoint', 'word', 'excel', 'link', 'mixed'))
            ");
        }
    }
};
