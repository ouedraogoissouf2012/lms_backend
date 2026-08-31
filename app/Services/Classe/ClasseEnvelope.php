<?php

declare(strict_types=1);

namespace App\Services\Classe;

/**
 * Lecture DÉFENSIVE de l'enveloppe KLASSCI `GET classes/{id}`.
 *
 * KLASSCI livre `data` sous forme d'enveloppe — `{classe, etudiants, matieres,
 * evaluations, emploi_temps_semaine, statistiques}` — et non comme la classe à
 * plat. Chaque bloc y est déjà présent : le redemander par un appel séparé est
 * superflu, et parfois refusé en amont (`classes/{id}/etudiants` → 403 par
 * classe) ou tout simplement faux (le catalogue `matieres?…` ignore ses filtres).
 *
 * Ce lecteur est PUR (aucune dépendance, aucune I/O), sur le modèle de
 * {@see \App\Services\Seances\KlassciPayload} : il narrowe du `mixed` non typé
 * sans jamais lever d'exception.
 *
 * Distinction structurante : `optionalList()` renvoie `null` quand le bloc est
 * ABSENT, `[]` quand il est livré et réellement vide. Sans elle, une absence
 * amont se publierait comme une mesure (« 0 matière »).
 *
 * @see app/Services/Classe/ClasseDetailsQueryService.php
 */
final class ClasseEnvelope
{
    /**
     * Bloc `classe` de l'enveloppe, ou `null` s'il est absent/malformé.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function classe(?array $payload): ?array
    {
        $classe = is_array($payload) ? ($payload['classe'] ?? null) : null;

        /** @var array<string, mixed>|null $classe */
        return is_array($classe) ? $classe : null;
    }

    /**
     * Roster livré avec la classe. Toujours un tableau : l'appelant distingue
     * lui-même « aucun étudiant » d'une classe introuvable (statut 404).
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<int, array<string, mixed>>
     */
    public static function etudiants(?array $payload): array
    {
        return self::optionalList($payload, 'etudiants') ?? [];
    }

    /**
     * Bloc de liste, en distinguant ABSENT (`null`) de VIDE (`[]`).
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<int, array<string, mixed>>|null
     */
    public static function optionalList(?array $payload, string $key): ?array
    {
        if (!is_array($payload) || !is_array($payload[$key] ?? null)) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $list */
        $list = array_values(array_filter(
            $payload[$key],
            static fn (mixed $item): bool => is_array($item),
        ));

        return $list;
    }
}
