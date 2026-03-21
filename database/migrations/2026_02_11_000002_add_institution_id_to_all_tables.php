<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables qui doivent recevoir la colonne institution_id
     */
    private array $tables = [
        'users',
        'classes',
        'matieres',
        'lessons',
        'chapters',
        'evaluations',
        'evaluation_questions',
        'evaluation_submissions',
        'seances',
        'esbtp_attendance',
        'lesson_progress',
        'chapter_progress',
        'forum_categories',
        'forum_topics',
        'forum_posts',
        'files',
        'notifications',
        'quizzes',
        'quiz_questions',
        'quiz_answers',
        'quiz_attempts',
        'knowledge_checks',
        'knowledge_check_attempts',
        'user_classes',
        'matiere_enseignant',
        'lesson_resources',
        'lms_enseignants_cache',
        'seance_user_hidden',
        'classe_etudiant',
        'classe_matiere',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'institution_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedBigInteger('institution_id')->nullable()->after('id');
                    $table->index('institution_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'institution_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropIndex(['institution_id']);
                    $table->dropColumn('institution_id');
                });
            }
        }
    }
};
