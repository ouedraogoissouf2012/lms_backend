<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERF #519 — index composites « tenant-leading » manquants.
 *
 * `BelongsToInstitution` injecte `WHERE institution_id = ?` sur ~28 tables, mais
 * `institution_id` n'a qu'un index SIMPLE, inutilisable en tête des composites
 * existants (qui commencent par status/date). Chaque requête tenant-scopée
 * dégrade donc linéairement à mesure que tenants et lignes croissent.
 *
 * Cette migration ajoute 9 composites, chacun aligné sur une requête chaude
 * VÉRIFIÉE (fichier:ligne en commentaire). Noms EXPLICITES (≤ 64 car., limite
 * d'identifiant MySQL — le 4-colonnes auto-nommé la dépasserait) pour un `down()`
 * sans ambiguïté.
 *
 * Choix : purement ADDITIF. Les index simples `institution_id` préexistants sont
 * CONSERVÉS (aucun index supprimé → aucune régression de lecture). Ils deviennent
 * redondants sous MySQL avec les composites `(institution_id, …)` (préfixe
 * couvrant) : nettoyage optionnel laissé à un lot dédié (dette tracée, hors #519).
 */
return new class extends Migration
{
    public function up(): void
    {
        // seances — historique visio : institution_id=? AND visio_started_at IS NOT NULL
        //           ORDER BY visio_started_at DESC  (SeancesHistoryQueryService.php:76-101)
        // seances — calendrier manager : institution_id=? AND is_active=1
        //           AND date_seance BETWEEN … ORDER BY date_seance  (ManagerSeancesLocalFetcher.php:41-57)
        Schema::table('seances', function (Blueprint $table): void {
            $table->index(['institution_id', 'visio_started_at'], 'seances_inst_visio_started_idx');
            $table->index(['institution_id', 'is_active', 'date_seance'], 'seances_inst_active_date_idx');
        });

        // lessons — listing publié : institution_id=? AND status=? AND published_at<=now
        //           (Lesson::scopePublished:109-114 / LessonListService)
        // lessons — dashboard prof : enseignant_id=? AND status=?  (TeacherDashboardService.php:54-58)
        Schema::table('lessons', function (Blueprint $table): void {
            $table->index(['institution_id', 'status', 'published_at'], 'lessons_inst_status_published_idx');
            $table->index(['enseignant_id', 'status'], 'lessons_teacher_status_idx');
        });

        // evaluations — listing classe : institution_id=? AND klassci_classe_id=?
        //               AND is_published=? [AND status=?]  (EvaluationListService:56-68,
        //               StudentEvaluationsListService:57-58). `is_published` n'avait aucun index.
        Schema::table('evaluations', function (Blueprint $table): void {
            $table->index(
                ['institution_id', 'klassci_classe_id', 'is_published', 'status'],
                'evaluations_inst_classe_pub_status_idx',
            );
        });

        // notifications — stats admin : institution_id=? AND created_at>=? ; GROUP BY type
        //                 (NotificationQueryService.php:206-222). created_at n'existait qu'en
        //                 2e position de (user_id, created_at) ; type indexé mais non tenant-leading.
        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['institution_id', 'created_at'], 'notifications_inst_created_idx');
            $table->index(['institution_id', 'type'], 'notifications_inst_type_idx');
        });

        // forum_topics — listing tenant : institution_id=? AND status=? AND is_resolved=?
        //                (ForumTopicService.php:73 ; is_resolved n'avait aucun index)
        Schema::table('forum_topics', function (Blueprint $table): void {
            $table->index(['institution_id', 'status', 'is_resolved'], 'forum_topics_inst_status_resolved_idx');
        });

        // audit_logs — journal filtré : action=? ORDER BY created_at DESC (paginé)
        //              (AuditLogController::index → forAction + recent). Table append-only :
        //              +1 index en écriture, justifié par le tri sans filesort à grande échelle.
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['action', 'created_at'], 'audit_logs_action_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->dropIndex('seances_inst_visio_started_idx');
            $table->dropIndex('seances_inst_active_date_idx');
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex('lessons_inst_status_published_idx');
            $table->dropIndex('lessons_teacher_status_idx');
        });

        Schema::table('evaluations', function (Blueprint $table): void {
            $table->dropIndex('evaluations_inst_classe_pub_status_idx');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_inst_created_idx');
            $table->dropIndex('notifications_inst_type_idx');
        });

        Schema::table('forum_topics', function (Blueprint $table): void {
            $table->dropIndex('forum_topics_inst_status_resolved_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_action_created_idx');
        });
    }
};
