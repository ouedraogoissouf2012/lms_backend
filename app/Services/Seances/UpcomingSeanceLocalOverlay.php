<?php

declare(strict_types=1);

namespace App\Services\Seances;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Superpose l'état LOCAL du LMS sur une liste de séances déjà mise en forme :
 * les champs `visio_*` et la durée calculée.
 *
 * ## Pourquoi une classe séparée
 *
 * `UpcomingSeancesFetcher` marche KLASSCI et filtre ; superposer l'état local
 * est un autre métier. La séparation garde chacun sous la garde des 300 lignes
 * (§1.1) et rend cette superposition testable seule.
 *
 * ## Pourquoi le lookup est un ARGUMENT, jamais une dépendance injectée
 *
 * {@see LocalSeanceLookup} porte l'état du pré-chargement (#476) : il est
 * peuplé par `preload()` puis interrogé en mémoire. Il n'est pas un singleton —
 * l'injecter ici fournirait une instance DIFFÉRENTE et VIDE, et chaque séance
 * ressortirait « sans visio » sans qu'aucune erreur ne le signale. Le recevoir
 * en argument rend cette dépendance temporelle explicite et impossible à rater.
 */
final class UpcomingSeanceLocalOverlay
{
    /**
     * @param  Collection<int, array<string, mixed>>  $seances
     * @return Collection<int, array<string, mixed>>
     */
    public function apply(Collection $seances, LocalSeanceLookup $lookup): Collection
    {
        /** @var Collection<int, array<string, mixed>> $enriched */
        $enriched = $seances->map(function (array $seance) use ($lookup): array {
            $seance = self::withDureeMinutes($seance);

            // Résolu EN MÉMOIRE depuis le même pré-chargement que le filtre
            // (mutualisation #476, plus de N+1).
            $visio = $lookup->seanceFor(KlassciPayload::toInt($seance['id'] ?? null));

            // `array_merge` et non `+` : l'état local FAIT AUTORITÉ sur ce que
            // le mapper aurait pu poser — c'est la sémantique d'origine.
            return array_merge($seance, [
                'visio_enabled' => $visio !== null && $visio->visio_enabled,
                'visio_type' => $visio?->visio_type,
                'visio_room_id' => $visio?->visio_room_id,
                'visio_active' => $visio !== null && $visio->visio_active,
                'visio_started_at' => $visio?->visio_started_at?->toISOString(),
                'visio_ended_at' => $visio?->visio_ended_at?->toISOString(),
            ]);
        });

        return $enriched;
    }

    /**
     * Ajoute `duree_minutes` (entier, minutes) à partir des heures de
     * `programmation`, qui sont des datetimes ISO déjà alignés sur la date de
     * séance (UpcomingSeanceMapper + SeanceProgrammationNormalizer). On les parse
     * tels quels, SANS reconcaténer la date — cohérent avec
     * SeanceDetailQueryService (#487). Heure(s) manquante(s) → clé omise.
     *
     * @param  array<string, mixed>  $seance
     * @return array<string, mixed>
     */
    private static function withDureeMinutes(array $seance): array
    {
        $prog = KlassciPayload::asArray($seance['programmation'] ?? null);
        $heureDebutRaw = KlassciPayload::toStringOrNull($prog['heure_debut'] ?? null);
        $heureFinRaw = KlassciPayload::toStringOrNull($prog['heure_fin'] ?? null);

        if ($heureDebutRaw !== null && $heureFinRaw !== null) {
            $heureDebut = Carbon::parse($heureDebutRaw);
            $heureFin = Carbon::parse($heureFinRaw);
            $seance['duree_minutes'] = (int) $heureDebut->diffInMinutes($heureFin, absolute: true);
        }

        return $seance;
    }
}
