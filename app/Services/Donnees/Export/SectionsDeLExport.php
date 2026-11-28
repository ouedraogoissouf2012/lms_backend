<?php

declare(strict_types=1);

namespace App\Services\Donnees\Export;

use Illuminate\Database\ConnectionInterface;

/**
 * LE lieu des sections de l'export des données d'un élève (#1705), dans l'ordre de l'export. Une table nouvelle qui
 * porte des données d'un élève s'ajoute ICI, colonnes nommées une à une.
 *
 * Délibérément hors de l'export, et déclaré dans la note de conformité :
 * - les secrets (mot de passe, jetons) et les identifiants techniques de synchronisation ;
 * - le contenu des fichiers : leurs métadonnées y sont, le fichier se télécharge par sa route ;
 * - le journal d'audit : il relève de la sécurité et se lit, sur demande, auprès de l'administration ;
 * - les séances masquées (`seance_user_hidden`), une préférence d'affichage.
 */
final class SectionsDeLExport
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SectionCopies $copies,
    ) {}

    /** @return list<SectionDExport> */
    public function toutes(): array
    {
        return [
            $this->table('classes', 'Inscriptions aux classes', 'classe_etudiant', 'user_id',
                ['date_inscription', 'statut', 'starts_on', 'ends_on', 'financement_source', 'financement_montant', 'financement_reference', 'financement_statut'],
                jointure: ['table' => 'classes', 'cle' => 'classe_id', 'colonnes' => ['classe' => 'libelle', 'code_classe' => 'code']]),
            $this->table('classes_klassci', 'Classes connues de KLASSCI', 'user_classes', 'user_id',
                ['classe_nom', 'classe_libelle', 'synced_at']),
            $this->copies,
            $this->table('presences', 'Présences aux séances', 'esbtp_attendance', 'user_id',
                ['status', 'mark', 'is_validated', 'joined_at', 'left_at', 'duration_minutes', 'origin', 'ip_address', 'user_agent'],
                jointure: ['table' => 'seances', 'cle' => 'seance_id', 'colonnes' => ['seance' => 'titre', 'date_seance' => 'date_seance']]),
            $this->table('progression_lecons', 'Progression dans les leçons', 'lesson_progress', 'user_id',
                ['status', 'progress_percentage', 'time_spent_minutes', 'started_at', 'completed_at', 'last_accessed_at', 'notes', 'rating', 'feedback'],
                jointure: ['table' => 'lessons', 'cle' => 'lesson_id', 'colonnes' => ['lecon' => 'title']]),
            $this->table('progression_chapitres', 'Progression dans les chapitres', 'chapter_progress', 'user_id',
                ['completed_at', 'time_spent_seconds', 'quiz_score', 'quiz_passed'],
                jointure: ['table' => 'chapters', 'cle' => 'chapter_id', 'colonnes' => ['chapitre' => 'title']]),
            $this->table('quiz', 'Tentatives de quiz', 'quiz_attempts', 'user_id',
                ['attempt_number', 'status', 'started_at', 'submitted_at', 'completed_at', 'score', 'points_earned', 'points_possible', 'passed', 'answers', 'teacher_feedback'],
                ['answers'], ['table' => 'quizzes', 'cle' => 'quiz_id', 'colonnes' => ['quiz_titre' => 'title']]),
            $this->table('controles_de_connaissances', 'Contrôles de connaissances', 'knowledge_check_attempts', 'user_id',
                ['score', 'correct_answers', 'total_questions', 'passed', 'answers', 'started_at', 'completed_at'],
                ['answers'], ['table' => 'knowledge_checks', 'cle' => 'knowledge_check_id', 'colonnes' => ['controle' => 'title']]),
            $this->table('consentements', 'Consentements', 'consents', 'user_id',
                ['purpose', 'granted', 'granted_at', 'revoked_at', 'seance_id', 'evidence', 'created_at'], ['evidence']),
            $this->table('forum_sujets', 'Sujets du forum', 'forum_topics', 'user_id',
                ['title', 'content', 'status', 'created_at', 'updated_at', 'deleted_at']),
            $this->table('forum_messages', 'Messages du forum', 'forum_posts', 'user_id',
                ['content', 'is_edited', 'edited_at', 'created_at', 'deleted_at'],
                jointure: ['table' => 'forum_topics', 'cle' => 'topic_id', 'colonnes' => ['sujet' => 'title']]),
            $this->table('notifications', 'Notifications', 'notifications', 'user_id',
                ['type', 'title', 'message', 'read_at', 'created_at']),
            $this->table('fichiers', 'Fichiers déposés', 'files', 'user_id',
                ['original_name', 'mime_type', 'size_bytes', 'category', 'description', 'created_at', 'deleted_at']),
        ];
    }

    /**
     * @param  list<string>  $colonnes
     * @param  list<string>  $json
     * @param  array{table: string, cle: string, colonnes: array<string, string>}|null  $jointure
     */
    private function table(string $cle, string $titre, string $table, string $colonneEleve, array $colonnes, array $json = [], ?array $jointure = null): SectionParTable
    {
        return new SectionParTable($this->db, $cle, $titre, $table, $colonneEleve, $colonnes, $json, $jointure);
    }
}
