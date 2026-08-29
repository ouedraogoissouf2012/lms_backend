<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #566 — la suppression d'un utilisateur devient LOGIQUE (soft delete).
 *
 * `DELETE /users/{id}` faisait un hard delete : les FK `onDelete('cascade')`
 * détruisaient quiz_attempts, evaluation_submissions, forum_posts, notifications.
 * On ajoute `deleted_at` (indexé) pour activer le trait SoftDeletes sur User.
 *
 * Les index uniques existants — users_email_institution_unique et
 * users_klassci_id_institution_id_unique — restent inchangés : MySQL ne supporte
 * pas d'index unique partiel, donc une ligne soft-deletée occupe toujours son
 * emplacement d'unicité. C'est pourquoi la re-synchronisation KLASSCI RESTAURE
 * la ligne (KlassciUserSynchronizer) au lieu d'en insérer une nouvelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();       // deleted_at TIMESTAMP NULL
            $table->index('deleted_at'); // filtrage rapide du SoftDeletingScope
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
