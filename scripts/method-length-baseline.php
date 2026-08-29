<?php

declare(strict_types=1);

/**
 * Dette tracée — méthodes dépassant les 40 lignes de PRODUCTION_STANDARDS.md §5.
 *
 * Chaque entrée est TOLÉRÉE à sa longueur actuelle, jamais au-delà : `check-method-sizes.php`
 * échoue si une de ces méthodes grossit, et refuse toute NOUVELLE méthode trop longue.
 * C'est un cliquet : la dette ne peut que diminuer.
 *
 * Quand tu réduis une méthode, le script te signale l'écart — mets à jour la valeur ici.
 * Quand elle repasse sous 40 lignes, supprime la ligne.
 *
 * Généré le 2026-08-29 — 49 entrées.
 *
 * @return array<string, int>
 */
return [
    'app/Console/Commands/PurgeSeanceRecordings.php::handle'                                     => 49,
    'app/Http/Controllers/API/Evaluation/EvaluationKlassciSyncController.php::syncNotesToKlassci' => 50,
    'app/Http/Controllers/API/Evaluation/EvaluationKlassciSyncController.php::syncToKlassci'     => 42,
    'app/Http/Controllers/API/Evaluation/Student/EvaluationStudentSubmissionController.php::getMySubmission' => 60,
    'app/Http/Controllers/API/LMS/LMSEnseignantsController.php::getEnseignantsFromKlassci'       => 44,
    'app/Http/Controllers/API/LMS/LMSMatieresAdminController.php::adminMatieresList'             => 86,
    'app/Http/Requests/StoreChapterRequest.php::rules'                                           => 45,
    'app/Http/Requests/StoreLessonRequest.php::rules'                                            => 50,
    'app/Jobs/CleanOldEvaluations.php::handle'                                                   => 49,
    'app/Jobs/DetectDisconnectedParticipants.php::handle'                                        => 46,
    'app/Jobs/ProcessSeanceRecordingReady.php::handle'                                           => 41,
    'app/Services/AdminAnalytics/ActivityTrendsService.php::aggregate'                           => 50,
    'app/Services/AdminAnalytics/SystemMetricsService.php::aggregate'                            => 52,
    'app/Services/Attendances/SeanceAttendancesQueryService.php::getAttendances'                 => 45,
    'app/Services/Attendances/VideoSessionAttendancesSyncer.php::sync'                           => 59,
    'app/Services/Chapter/ChapterCrudService.php::create'                                        => 46,
    'app/Services/Chapter/ChapterProgressService.php::markAsCompleted'                           => 48,
    'app/Services/Chapter/ChapterProgressService.php::resetLessonProgress'                       => 47,
    'app/Services/ConvertApiService.php::convertPowerPointToImages'                              => 52,
    'app/Services/ConvertApiService.php::convertWordToImages'                                    => 52,
    'app/Services/Evaluation/EvaluationCreationService.php::create'                              => 49,
    'app/Services/Evaluation/Teacher/TeacherEvaluationResultsService.php::getResultsByClass'     => 64,
    'app/Services/Evaluation/Teacher/TeacherEvaluationViewService.php::getSubmissions'           => 56,
    'app/Services/Evaluation/Teacher/TeacherEvaluationViewService.php::preview'                  => 62,
    'app/Services/Institution/InstitutionConnectionTester.php::test'                             => 43,
    'app/Services/KnowledgeCheck/KnowledgeCheckAttemptService.php::submitAttempt'                => 42,
    'app/Services/Matiere/MatiereEvaluationsFetcher.php::fetchLmsOnlyEvaluations'                => 41,
    'app/Services/Quiz/QuizAttemptStateService.php::checkTimeRemaining'                          => 42,
    'app/Services/Report/AttendanceReportContextBuilder.php::build'                              => 44,
    'app/Services/Report/ReportGenerationService.php::generateGrades'                            => 49,
    'app/Services/SeanceDetailQueryService.php::getSeanceDetailsArray'                           => 41,
    'app/Services/Seances/Mutations/ParticipantValidationService.php::validate'                  => 65,
    'app/Services/Seances/Mutations/SeanceDeleteService.php::delete'                             => 48,
    'app/Services/Seances/Mutations/SeanceHideService.php::unhide'                               => 46,
    'app/Services/Seances/Mutations/VisioToggleService.php::toggle'                              => 61,
    'app/Services/Seances/SeanceVisioEnricher.php::loadFromLocalDbFallback'                      => 47,
    'app/Services/Seances/StudentClassesSeancesFetcher.php::fetch'                               => 47,
    'app/Services/Seances/StudentClassesSeancesFetcher.php::mapStudentSeances'                   => 46,
    'app/Services/Seances/TeachingSeancesFetcher.php::fetch'                                     => 42,
    'app/Services/Seances/TeachingSeancesFetcher.php::mapSeance'                                 => 45,
    'app/Services/SeancesHistoryQueryService.php::enrichWithStats'                               => 42,
    'app/Services/Visio/Lifecycle/VisioActivationService.php::activate'                          => 63,
    'app/Services/Visio/Lifecycle/VisioActivationService.php::deactivate'                        => 46,
    'app/Services/Visio/Lifecycle/VisioSessionService.php::end'                                  => 55,
    'app/Services/Visio/Lifecycle/VisioSessionService.php::start'                                => 56,
    'app/Services/Visio/VisioHeartbeatService.php::heartbeat'                                    => 48,
    'app/Services/Visio/VisioParticipantSessionService.php::join'                                => 73,
    'app/Services/Visio/VisioParticipantSessionService.php::leave'                               => 42,
    'app/Services/Visio/VisioParticipantsStatsBuilder.php::build'                                => 47,
];
