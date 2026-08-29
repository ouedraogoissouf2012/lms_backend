<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #605 — Aligner `chapters.video_provider` entre MySQL et SQLite.
 *
 * Divergence de schéma : MySQL portait encore l'ENUM `['youtube','vimeo','custom']`
 * (posé par `2025_10_27_081047_restructure_lessons_and_chapters_relationship.php:70`
 * et NON modifié par `2026_01_03_220000...:87` qui ne touche que `content_type`),
 * alors que la reconstruction SQLite `2026_01_03_220000...:24-59` l'avait passé en
 * `VARCHAR(50)`.
 *
 * Le code d'attache automatique de replay visio
 * (`app/Services/Visio/Recording/SeanceRecordingAttachmentResolver.php:153`, défaut
 * `'external'` ligne 27, aussi `'s3'`) écrit des providers hors de cet ENUM → sous
 * MySQL (mode strict) : `SQLSTATE[01000] 1265 Data truncated for column
 * 'video_provider'` → l'attache échouait EN PRODUCTION. SQLite le masquait.
 *
 * On normalise MySQL en `VARCHAR(50)` (permissif, comme SQLite). Découvert par la
 * jambe MySQL de la CI (#574).
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL uniquement : SQLite porte déjà VARCHAR(50) depuis la
        // reconstruction 2026_01_03 (aucune action, aucun ENUM natif SQLite).
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE chapters MODIFY COLUMN video_provider VARCHAR(50) NULL');
        }
    }

    public function down(): void
    {
        // Réversion : restaure l'ENUM historique (peut échouer si des lignes
        // portent un provider hors-liste, ce qui est précisément le cas que
        // cette migration débloque — la réversion n'est pas sans perte).
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE chapters MODIFY COLUMN video_provider ENUM('youtube','vimeo','custom') NULL");
        }
    }
};
