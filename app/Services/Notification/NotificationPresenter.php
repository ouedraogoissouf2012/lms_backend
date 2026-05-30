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
 * `getIcon()` et `getColor()` restent sur le modèle : pure `match` enum sans
 * dépendance externe — extraction = bruit sans valeur.
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

        return match ($notification->type) {
            Notification::TYPE_LESSON_PUBLISHED, Notification::TYPE_LESSON_UPDATED =>
                isset($data['lesson_id']) ? "/lessons/{$data['lesson_id']}" : null,

            Notification::TYPE_FORUM_REPLY, Notification::TYPE_FORUM_SOLUTION =>
                isset($data['topic_id']) ? "/forum/topics/{$data['topic_id']}" : null,

            Notification::TYPE_QUIZ_AVAILABLE,
            Notification::TYPE_GRADE_RECEIVED,
            Notification::TYPE_QUIZ_DEADLINE =>
                isset($data['quiz_id']) ? "/quizzes/{$data['quiz_id']}" : null,

            Notification::TYPE_VISIO_SCHEDULED, Notification::TYPE_VISIO_STARTING =>
                isset($data['seance_id']) ? "/seances/{$data['seance_id']}" : null,

            Notification::TYPE_EVALUATION_APPROACHING =>
                isset($data['evaluation_id']) ? "/student/evaluations/{$data['evaluation_id']}" : null,

            default => null,
        };
    }
}
