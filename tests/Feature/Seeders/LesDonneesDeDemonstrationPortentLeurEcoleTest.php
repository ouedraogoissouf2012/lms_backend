<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #801 — les données de démonstration portent leur école.
 *
 * Un seeder n'a pas de tenant : ce que `BelongsToInstitution` aurait rempli restait sans
 * école. Mesuré en rendant `institution_id` obligatoire : `DemoDataSeeder` écrivait ses
 * notifications sans école, et sa catégorie, ses sujets et son message de forum de même.
 */
final class LesDonneesDeDemonstrationPortentLeurEcoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_aucune_ligne_de_demonstration_n_est_sans_ecole(): void
    {
        ob_start();
        try {
            $this->seed(DemoDataSeeder::class);
        } finally {
            ob_end_clean();
        }

        $tables = ['lessons', 'quizzes', 'quiz_questions', 'notifications', 'forum_categories', 'forum_topics', 'forum_posts'];
        $sansEcole = [];
        foreach ($tables as $table) {
            self::assertGreaterThan(0, DB::table($table)->count(), "Le seeder devait écrire dans {$table}.");
            $nulles = DB::table($table)->whereNull('institution_id')->count();
            if ($nulles > 0) {
                $sansEcole[$table] = $nulles;
            }
        }

        self::assertSame([], $sansEcole, 'Lignes de démonstration sans école, par table :');
    }
}
