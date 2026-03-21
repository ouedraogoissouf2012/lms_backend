<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstitutionSeeder extends Seeder
{
    /**
     * Tables à backfill avec institution_id
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

    public function run(): void
    {
        // 1. Créer les institutions
        $institutions = [
            [
                'slug' => 'presentation',
                'name' => 'KLASSCI Présentation',
                'klassci_api_url' => env('KLASSCI_PRESENTATION_URL', 'http://presentation.klassci.com/api/lms'),
                'klassci_api_token' => env('KLASSCI_PRESENTATION_TOKEN', env('KLASSCI_API_TOKEN')),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'esbtp-abidjan',
                'name' => 'ESBTP Abidjan',
                'klassci_api_url' => env('KLASSCI_ESBTP_ABIDJAN_URL', 'https://esbtp-abidjan.klassci.com/api/lms'),
                'klassci_api_token' => env('KLASSCI_ESBTP_ABIDJAN_TOKEN'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'esbtp-yakro',
                'name' => 'ESBTP Yakro',
                'klassci_api_url' => env('KLASSCI_ESBTP_YAKRO_URL', 'https://esbtp-yakro.klassci.com/api/lms'),
                'klassci_api_token' => env('KLASSCI_ESBTP_YAKRO_TOKEN'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($institutions as $institution) {
            DB::table('institutions')->updateOrInsert(
                ['slug' => $institution['slug']],
                $institution
            );
        }

        // 2. Backfill : assigner toutes les données existantes à "presentation"
        $presentationId = DB::table('institutions')->where('slug', 'presentation')->value('id');

        if ($presentationId) {
            foreach ($this->tables as $tableName) {
                if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'institution_id')) {
                    $updated = DB::table($tableName)
                        ->whereNull('institution_id')
                        ->update(['institution_id' => $presentationId]);

                    if ($updated > 0) {
                        $this->command->info("  → {$tableName}: {$updated} lignes assignées à 'presentation'");
                    }
                }
            }
        }
    }
}
