<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le registre UNIQUE des motifs d'échec d'un enregistrement visio.
 *
 * Le motif d'échec (`seance_recordings.error_message`) fait partie du contrat
 * de `SeanceRecording::toRecordingPayload()` et du cache d'état : il ne porte
 * qu'une valeur de ce registre. Le détail technique va au journal.
 *
 * Les écrivains n'écrivent que ces valeurs. À la sortie, une valeur hors du
 * registre (une ligne écrite avant lui, ou un écrivain qui se tromperait) est
 * servie comme `ProcessingException` : la liste blanche est à la frontière, et
 * non dans les données.
 *
 * Les deux dernières valeurs sont des phrases, et non des clés : l'écran les
 * affiche déjà telles quelles. Leur passage en clés suivra le lot des libellés
 * du front, qui s'appuiera sur ce registre.
 */
enum RecordingFailureReason: string
{
    // Rattachement de la vidéo à une leçon (SeanceRecordingAttachmentResolver).
    case MissingKlassciMatiereId = 'missing_klassci_matiere_id';
    case MatiereNotFound = 'matiere_not_found';
    case LessonNotFound = 'lesson_not_found';
    case AmbiguousLesson = 'ambiguous_lesson';

    // Import du fichier Jibri (ImportJibriRecordingMedia).
    case MediaNotFound = 'media_not_found';
    case MediaCopyFailed = 'media_copy_failed';
    case StorageException = 'storage_exception';

    // Traitement de la vidéo prête (ProcessSeanceRecordingReady) ; le second est
    // aussi le repli de toute valeur hors registre.
    case SeanceNotFound = 'seance_not_found';
    case ProcessingException = 'processing_exception';

    // Enregistrement resté bloqué (StaleRecordingFailer).
    case NoMediaReceived = 'Aucun fichier reçu du fournisseur d\'enregistrement dans le délai imparti.';
    case NeverStopped = 'Enregistrement jamais arrêté : la visioconférence est terminée ou la durée maximale est dépassée.';

    /**
     * La valeur que le client peut recevoir pour ce qui est stocké. Sans motif
     * (`null` ou vide), rien : comme le cache d'état, qui lit `''` en `null`.
     */
    public static function servable(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return (self::tryFrom($stored) ?? self::ProcessingException)->value;
    }
}
