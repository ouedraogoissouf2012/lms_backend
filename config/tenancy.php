<?php

declare(strict_types=1);

/*
 * #1868 : les tables tenant-scopées NEUVES se déclarent chacune dans leur fragment,
 * `database/tenancy/<issue>-<sujet>.php`, qui rend une liste de noms. Ils sont ajoutés ici,
 * triés par nom de fichier : plus de liste partagée qui rouvre les conflits des PR. La liste
 * ci-dessous garde l'existant, sans le déplacer. Évalué une fois par `config:cache`.
 */
$tablesDesFragments = [];
$fragmentsDeTenancy = glob(dirname(__DIR__).'/database/tenancy/*.php') ?: [];
sort($fragmentsDeTenancy);
foreach ($fragmentsDeTenancy as $fragmentDeTenancy) {
    array_push($tablesDesFragments, ...array_values((array) require $fragmentDeTenancy));
}

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
    | RÈGLE : toute nouvelle table tenant-scopée DOIT être déclarée dans son
    | fragment `database/tenancy/<issue>-<sujet>.php` (#1868), jamais dans la
    | liste ci-dessous, ET recevoir sa clé étrangère `institution_id -> institutions(id)`.
    |
    */

    'institution_scoped_tables' => [
        'users',
        // #800 — l'entite racine du monde autonome. institution_id est NOT NULL
        // sur ces trois tables neuves, contrairement aux historiques.
        'programs',
        'training_sessions',
        // #1650 : les annonces à une classe, institution_id NOT NULL.
        'annonces',
        // #1657 : le dossier de l'élève, ses tuteurs, la séquence du matricule (institution_id NOT NULL).
        'dossiers_eleves',
        'tuteurs',
        'matricules_sequences',
        'creneaux',
        // #1648 — filières et niveaux d'une école qui tient son catalogue ; tables neuves, NOT NULL.
        'filieres',
        'niveaux_etudes',
        // #1654 — l'arbre LMD d'une école qui tient son catalogue ; tables neuves, NOT NULL.
        'lmd_domaines',
        'lmd_mentions',
        'lmd_parcours',
        // #1806 — les sous-périodes d'une période ; table neuve, NOT NULL.
        'sous_periodes',
        'classes',
        'matieres',
        'lessons',
        'chapters',
        'evaluations',
        'evaluation_questions',
        'evaluation_submissions',
        // #1702 (D1) : la banque de questions.
        'categories_de_questions',
        'questions_de_banque',
        'seances',
        'esbtp_attendance',
        // #1680 : les codes d'émargement d'une séance.
        'codes_emargement',
        // #1681 : les justificatifs d'absence (le pivot des séances n'a pas d'école propre).
        'justificatifs_absence',
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
        'consents',
        'classe_etudiant',
        'classe_matiere',
        // #1868 : les tables neuves, chacune dans son fragment (database/tenancy/).
        ...$tablesDesFragments,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables portant institution_id HORS de la liste ci-dessus (#719)
    |--------------------------------------------------------------------------
    |
    | Une table qui porte `institution_id` est, soit déclarée ci-dessus (et porte
    | sa clé RESTRICT), soit nommée ici avec sa raison. La garde
    | `LaConfigDesTenantsSuitLeSchemaTest` compare les deux au schéma réel, dans
    | les deux sens, et exige que chaque exception existe : une exception morte
    | masquerait une table renommée. Si une table disparaît (par exemple
    | `password_reset_tokens` avec le volet serveur de #1306), son exception
    | disparaît avec elle.
    |
    */

    'institution_scoped_exceptions' => [
        'audit_logs' => 'Journal d\'audit : il doit survivre à son école. Aucune clé, purgé par sa propre politique (AuditLogsPolicy).',
        'imports' => 'Clé en CASCADE : un rapport d\'import n\'a pas de sens sans son école. Purge des fichiers sources : #1487.',
        'password_reset_tokens' => 'Clé en CASCADE : un jeton de réinitialisation meurt avec son école (volet serveur de #1306).',
        'school_registration_requests' => 'Clé en SET NULL : la demande d\'inscription d\'une école précède l\'école et lui survit.',
        'seance_recordings' => 'Rattachée par seance_id en CASCADE ; institution_id n\'y porte pas de clé. Destruction des médias : #1487.',
        'purge_log' => 'Journal de conformité des purges : clé en SET NULL, il doit survivre à la purge de son sujet (#719).',
    ],

];
