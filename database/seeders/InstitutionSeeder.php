<?php

namespace Database\Seeders;

use App\Models\Institution;
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
                // #685 : ce defaut etait en `http://`. KLASSCI ne repond plus sur
                // le port 80 — mesure du 2026-09-03 : code 000 apres 10 s en
                // clair, 404 en 1,74 s en TLS. Un `db:seed` reintroduisait donc
                // la panne que #768 venait de corriger, et la regle
                // `App\Rules\KlassciApiUrl` refuse desormais cette forme a
                // l'enregistrement : le seeder ecrivait une valeur que l'API
                // elle-meme rejetterait.
                'klassci_api_url' => env('KLASSCI_PRESENTATION_URL', 'https://presentation.klassci.com/api/lms'),
                'klassci_api_token_encrypted' => env('KLASSCI_PRESENTATION_TOKEN', env('KLASSCI_API_TOKEN')),
                'is_active' => true,
            ],
            [
                'slug' => 'esbtp-abidjan',
                'name' => 'ESBTP Abidjan',
                'klassci_api_url' => env('KLASSCI_ESBTP_ABIDJAN_URL', 'https://esbtp-abidjan.klassci.com/api/lms'),
                'klassci_api_token_encrypted' => env('KLASSCI_ESBTP_ABIDJAN_TOKEN'),
                'is_active' => true,
            ],
            [
                'slug' => 'esbtp-yakro',
                'name' => 'ESBTP Yakro',
                'klassci_api_url' => env('KLASSCI_ESBTP_YAKRO_URL', 'https://esbtp-yakro.klassci.com/api/lms'),
                'klassci_api_token_encrypted' => env('KLASSCI_ESBTP_YAKRO_TOKEN'),
                'is_active' => true,
            ],
        ];

        // #688 — un jeton absent ne doit pas passer en silence.
        //
        // `env('KLASSCI_ESBTP_YAKRO_TOKEN')` sans defaut rend `null` quand la
        // variable manque. L'institution etait alors creee, `is_active = true`,
        // et le seeder annoncait un succes — mais ce tenant ne pouvait plus
        // parler a KLASSCI. Meme famille que le symptome rapporte : un seeder
        // qui n'a pas fait ce qu'il annonce, sans le dire.
        //
        // On nomme la variable manquante, comme le fait SupradminSeeder pour les
        // siennes. On n'echoue pas : un poste de developpement sans jeton reste
        // legitime, et faire echouer `db:seed` y serait plus nuisible qu'utile.
        $variablesDeJeton = [
            'presentation' => 'KLASSCI_PRESENTATION_TOKEN (ou KLASSCI_API_TOKEN)',
            'esbtp-abidjan' => 'KLASSCI_ESBTP_ABIDJAN_TOKEN',
            'esbtp-yakro' => 'KLASSCI_ESBTP_YAKRO_TOKEN',
        ];

        foreach ($institutions as $institution) {
            $jeton = $institution['klassci_api_token_encrypted'] ?? null;

            if (! is_string($jeton) || $jeton === '') {
                $this->say(
                    'warn',
                    "  ⚠ {$institution['slug']} : jeton KLASSCI ABSENT — variable "
                    ."{$variablesDeJeton[$institution['slug']]} non definie. "
                    .'Le tenant est cree mais ne pourra pas joindre KLASSCI.'
                );
            }

            Institution::updateOrCreate(
                ['slug' => $institution['slug']],
                $institution
            );
        }

        // 2. Backfill : assigner toutes les données existantes à "presentation"
        $presentationId = Institution::where('slug', 'presentation')->value('id');

        if ($presentationId) {
            foreach ($this->tables as $tableName) {
                if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'institution_id')) {
                    $updated = DB::table($tableName)
                        ->whereNull('institution_id')
                        ->update(['institution_id' => $presentationId]);

                    if ($updated > 0) {
                        $this->say('info', "  → {$tableName}: {$updated} lignes assignées à 'presentation'");
                    }
                }
            }
        }
    }

    /**
     * Ecrit sur la console quand il y en a une.
     *
     * `$this->command` est `null` des que le seeder tourne hors d'une commande
     * Artisan — le cas d'un appel direct depuis un test. Sans cette garde, un
     * seeder qui a parfaitement fait son travail se termine sur un
     * « Call to a member function info() on null ».
     */
    private function say(string $niveau, string $message): void
    {
        $this->command?->{$niveau}($message);
    }
}
