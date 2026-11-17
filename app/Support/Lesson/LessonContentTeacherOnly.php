<?php

declare(strict_types=1);

namespace App\Support\Lesson;

use Illuminate\Http\JsonResponse;

/**
 * Le contenu d'une leçon est l'affaire de son enseignant (#1167, décisions du user
 * du 17/11).
 *
 * Sur une leçon, ses chapitres, leurs fichiers et les quiz de chapitre, le
 * coordinateur et l'admin d'école ne font que consulter. Toute écriture refusée à
 * un compte qui n'est pas enseignant porte ce motif, un seul pour tout le contenu
 * (ADR-906-01) ; le front le lit dans son catalogue. L'enseignant qui n'est pas
 * l'auteur reçoit, lui, le 403 générique : c'est l'autre sens du statut.
 */
final class LessonContentTeacherOnly
{
    public const REASON = 'lesson_content_teacher_only';

    public const MESSAGE = "Le contenu d'une leçon est réservé à son enseignant.";

    /** Réponse HTTP canonique du refus : 403, motif `lesson_content_teacher_only`. */
    public static function jsonResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => self::MESSAGE,
            'reason' => self::REASON,
        ], 403);
    }
}
