<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;

/**
 * Mapping présentation pour les notifications — construction d'URL d'action
 * extraite de `Notification` (PERF-04 batch 3).
 *
 * ## Pourquoi extraire
 *
 * `Notification::getActionUrl()` construisait des URLs front à partir du type
 * de la notification et du contenu de `$data`. C'est du mapping
 * URL/routing qui dépend des conventions d'URL frontend (vue routes), pas un
 * comportement métier de l'agrégat Notification. Centralisé ici pour
 * faciliter la maintenance quand les routes front bougeront (cf. spec
 * lms-data-controller-split — extraction de présenters envisagée).
 *
 * `icon()`, `color()` et `isRead()` déplacés ici depuis le modèle (H2 audit,
 * §5 modèles ≤150l) — mapping de présentation, même domaine que getActionUrl.
 *
 * ## DI strict (§1.6 D)
 *
 * Pas de dépendance constructeur — pure mapping sur le model passé en
 * argument. Pas de Facade.
 *
 * @see app/Models/Notification.php::getActionUrl (thin wrapper)
 */
class NotificationPresenter
{
    /**
     * Calcule l'URL d'action front pour une notification donnée.
     *
     * Retourne `null` quand le payload `data` ne contient pas l'identifiant
     * requis pour construire l'URL (lesson_id, topic_id, quiz_id, seance_id,
     * evaluation_id selon le type).
     */
    public function getActionUrl(Notification $notification): ?string
    {
        $data = $notification->data ?? [];

        // Helper local : récupère un ID scalaire de `$data` (mixed après le
        // cast Eloquent `array`) et le stringify pour interpolation sûre dans
        // l'URL. Retourne `null` si l'ID est absent / non-scalaire — protège
        // contre les payloads malformés (ex. array imbriqué injecté).
        $idOf = static function (string $key) use ($data): ?string {
            $value = $data[$key] ?? null;
            return is_scalar($value) ? (string) $value : null;
        };

        return match ($notification->type) {
            Notification::TYPE_LESSON_PUBLISHED, Notification::TYPE_LESSON_UPDATED =>
                ($id = $idOf('lesson_id')) !== null ? "/lessons/{$id}" : null,

            Notification::TYPE_FORUM_REPLY, Notification::TYPE_FORUM_SOLUTION =>
                ($id = $idOf('topic_id')) !== null ? "/forum/topics/{$id}" : null,

            Notification::TYPE_QUIZ_AVAILABLE,
            Notification::TYPE_GRADE_RECEIVED,
            Notification::TYPE_QUIZ_DEADLINE =>
                ($id = $idOf('quiz_id')) !== null ? "/quizzes/{$id}" : null,

            Notification::TYPE_VISIO_SCHEDULED, Notification::TYPE_VISIO_STARTING =>
                ($id = $idOf('seance_id')) !== null ? "/seances/{$id}" : null,

            Notification::TYPE_EVALUATION_APPROACHING =>
                ($id = $idOf('evaluation_id')) !== null ? "/student/evaluations/{$id}" : null,

            default => null,
        };
    }

    /** La notification a-t-elle été lue ? */
    public function isRead(Notification $notification): bool
    {
        return $notification->read_at !== null;
    }

    /** Icône MDI selon le type. */
    public function icon(Notification $notification): string
    {
        return match ($notification->type) {
            Notification::TYPE_LESSON_PUBLISHED => 'mdi-book-open',
            Notification::TYPE_FORUM_REPLY => 'mdi-message-reply',
            Notification::TYPE_QUIZ_AVAILABLE => 'mdi-clipboard-list',
            Notification::TYPE_GRADE_RECEIVED => 'mdi-star',
            Notification::TYPE_LESSON_UPDATED => 'mdi-book-edit',
            Notification::TYPE_FORUM_SOLUTION => 'mdi-check-circle',
            Notification::TYPE_QUIZ_DEADLINE => 'mdi-clock-alert',
            Notification::TYPE_VISIO_SCHEDULED => 'mdi-video-outline',
            Notification::TYPE_VISIO_STARTING => 'mdi-video-check',
            Notification::TYPE_EVALUATION_APPROACHING => 'mdi-calendar-alert',
            default => 'mdi-bell',
        };
    }

    /** Couleur UI selon le type. */
    public function color(Notification $notification): string
    {
        return match ($notification->type) {
            Notification::TYPE_LESSON_PUBLISHED => 'primary',
            Notification::TYPE_FORUM_REPLY => 'info',
            Notification::TYPE_QUIZ_AVAILABLE => 'warning',
            Notification::TYPE_GRADE_RECEIVED => 'success',
            Notification::TYPE_LESSON_UPDATED => 'primary',
            Notification::TYPE_FORUM_SOLUTION => 'success',
            Notification::TYPE_QUIZ_DEADLINE => 'error',
            Notification::TYPE_VISIO_SCHEDULED => 'info',
            Notification::TYPE_VISIO_STARTING => 'warning',
            Notification::TYPE_EVALUATION_APPROACHING => 'warning',
            default => 'secondary',
        };
    }
}
