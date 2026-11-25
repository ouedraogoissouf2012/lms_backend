<?php

declare(strict_types=1);

use App\Services\Tenancy\GardeDesInstitutionsNulles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #801 (PR 2) — `institution_id` devient obligatoire sur les tables tenant-scopées.
 *
 * ## Le défaut
 *
 * `2026_02_11_000002` posait la colonne nullable. Or un unique composite par école ne mord
 * pas sur une ligne à NULL : plusieurs NULL sont admis dans un index unique. Une ligne sans
 * école échappait donc à l'isolation que ces uniques sont censés garantir.
 *
 * ## La liste est FIGÉE ici
 *
 * Les 29 tables qui portaient la colonne NULLABLE le 23/11 (mesuré sur un schéma neuf) : la
 * liste déclarée, moins `users` (le supradmin y garde NULL, PR 3 de #801), moins les quatre
 * déjà obligatoires (`consents`, `creneaux`, `programs`, `training_sessions`). Une migration ne
 * relit pas une configuration qui évoluera après elle : `down()` ne doit rendre facultatives
 * que les colonnes que `up()` a rendues obligatoires.
 *
 * ## La garde, AVANT tout DDL
 *
 * {@see GardeDesInstitutionsNulles} annule s'il reste une ligne sans école, sans état partiel
 * et sans rien supprimer. En production (fusion = déploiement), c'est le déploiement qui
 * échoue, avec les tables, leur compte et la commande d'audit dans son journal.
 *
 * Les clés vers `institutions` sont en RESTRICT : passer la colonne en NOT NULL ne touche
 * aucune d'elles.
 */
return new class extends Migration
{
    private const TABLES = [
        'classes', 'matieres', 'lessons', 'chapters', 'evaluations', 'evaluation_questions',
        'evaluation_submissions', 'seances', 'esbtp_attendance', 'lesson_progress',
        'chapter_progress', 'forum_categories', 'forum_topics', 'forum_posts', 'files',
        'notifications', 'quizzes', 'quiz_questions', 'quiz_answers', 'quiz_attempts',
        'knowledge_checks', 'knowledge_check_attempts', 'user_classes', 'matiere_enseignant',
        'lesson_resources', 'lms_enseignants_cache', 'seance_user_hidden', 'classe_etudiant',
        'classe_matiere',
    ];

    public function up(): void
    {
        app(GardeDesInstitutionsNulles::class)->exigerAucunNull(self::TABLES);

        foreach (self::TABLES as $table) {
            $this->rendre($table, false);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->rendre($table, true);
        }
    }

    /** Une table à la fois, et seulement si elle n'est pas déjà dans l'état voulu : rejouable. */
    private function rendre(string $table, bool $facultative): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'institution_id')) {
            return;
        }

        foreach (Schema::getColumns($table) as $colonne) {
            if ($colonne['name'] === 'institution_id' && (bool) $colonne['nullable'] === $facultative) {
                return;
            }
        }

        Schema::table($table, static function (Blueprint $colonnes) use ($facultative): void {
            $colonnes->unsignedBigInteger('institution_id')->nullable($facultative)->change();
        });
    }
};
