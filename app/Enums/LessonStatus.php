<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut de publication d'une {@see \App\Models\Lesson} (#522).
 *
 * Remplace les magic strings `'draft'`/`'published'`/`'archived'` disséminées
 * dans ~13 fichiers. Backed string : la valeur sérialisée (JSON client, colonne
 * DB) reste STRICTEMENT identique au contrat existant. Même patron que
 * {@see Role} et {@see SeanceRecordingStatus}.
 *
 * NB : les statuts de `Quiz` et d'`Evaluation` sont des domaines DISTINCTS
 * (mêmes chaînes, sémantique propre) — hors périmètre de cet enum.
 */
enum LessonStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * Vrai si ce statut est « publié » (au sens du cycle de vie éditorial).
     * Distinct de {@see \App\Models\Lesson::isPublished()}, qui vérifie EN PLUS
     * que `published_at` est passé.
     */
    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    /**
     * Valeurs backing, pour les règles de validation `in:...`.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }
}
