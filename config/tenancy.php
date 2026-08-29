<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tables tenant-scopées (portant institution_id)
    |--------------------------------------------------------------------------
    |
    | Source UNIQUE de vérité des tables portant la colonne `institution_id`
    | (issue #583). Dérivée de la migration d'origine qui a ajouté la colonne :
    | `database/migrations/2026_02_11_000002_add_institution_id_to_all_tables.php`.
    |
    | Consommée par :
    |   - App\Services\Tenancy\InstitutionIntegrityInspector (mesure / garde)
    |   - App\Console\Commands\AuditInstitutionOrphans (audit lecture seule)
    |   - la migration ajoutant les clés étrangères ON DELETE RESTRICT (#583)
    |
    | RÈGLE : toute nouvelle table tenant-scopée DOIT être ajoutée ici ET
    | recevoir sa clé étrangère `institution_id -> institutions(id)`.
    |
    */

    'institution_scoped_tables' => [
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
    ],

];
