<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;
use Carbon\Carbon;

/**
 * Projette un payload séance KLASSCI (+ sa matière + son propriétaire) vers la
 * forme de cache local d'une {@see Seance}, et applique ce cache à une entité.
 *
 * ## Issue #474 — DRY (§1.1)
 *
 * `localCacheData` / `updateLocalCacheData` / `parseSeanceStart` étaient
 * dupliqués à l'identique entre {@see \App\Jobs\SyncKlassciSeances} et
 * {@see TeachingSeancesFetcher}. Une correction de mapping appliquée à un seul
 * chemin aurait produit des `Seance` incohérentes entre le job planifié et le
 * fetch enseignant temps-réel. Ce collaborateur unique élimine ce risque.
 *
 * ## Isolation tenant (#473)
 *
 * `institution_id` est écrit EXPLICITEMENT depuis le propriétaire (`$owner`).
 * Indispensable côté job (scope global `BelongsToInstitution` inerte hors requête
 * HTTP) ; redondant mais cohérent côté fetcher (le hook `creating` le poserait
 * aussi). Le rendre explicite des deux côtés garantit un comportement identique.
 *
 * Fonction pure côté `build` (aucune I/O) ; `applyTo` persiste si nécessaire.
 */
final class SeanceCacheDataBuilder
{
    /**
     * Construit le tableau de cache local à partir du payload KLASSCI.
     *
     * @param  array<string, mixed>  $seance   Payload séance (programmation, classe)
     * @param  array<string, mixed>  $matiere  Payload matière (nom/libelle)
     * @param  User  $owner  Enseignant propriétaire (source de institution_id / nom)
     * @return array<string, mixed>
     */
    public function build(array $seance, array $matiere, User $owner): array
    {
        $prog = KlassciPayload::asArray($seance['programmation'] ?? null);
        $classe = KlassciPayload::asArray($seance['classe'] ?? null);
        $date = KlassciPayload::toStringOrNull($prog['date'] ?? null);
        $heureDebut = SeanceProgrammationNormalizer::alignDate(
            KlassciPayload::toStringOrNull($prog['heure_debut'] ?? null),
            $date
        );
        $matiereNom = $matiere['nom'] ?? $matiere['libelle'] ?? null;

        return [
            'institution_id' => $owner->institution_id,
            'klassci_matiere_id' => KlassciPayload::toInt($matiere['id'] ?? null),
            'klassci_classe_id' => KlassciPayload::toInt($classe['id'] ?? null),
            'klassci_enseignant_id' => $owner->klassci_id,
            'enseignant_nom' => $owner->name,
            'matiere_nom' => $matiereNom,
            'classe_nom' => KlassciPayload::toStringOrNull($classe['nom'] ?? null),
            'titre' => $matiereNom,
            'date_seance' => $this->parseSeanceStart($heureDebut, $date),
        ];
    }

    /**
     * Applique un tableau de cache à une séance existante, en n'écrasant que les
     * champs réellement renseignés (null = pas de valeur KLASSCI, on préserve
     * l'existant). Persiste uniquement si un champ a changé.
     *
     * @param  array<string, mixed>  $cacheData
     */
    public function applyTo(Seance $seance, array $cacheData): void
    {
        $updates = array_filter($cacheData, static fn ($value): bool => $value !== null);
        if ($updates === []) {
            return;
        }

        $seance->fill($updates);
        if ($seance->isDirty()) {
            $seance->save();
        }
    }

    /**
     * Résout le datetime de début de séance : heure_debut réalignée si présente
     * et parsable, sinon la date seule au début de journée, sinon null.
     */
    private function parseSeanceStart(?string $heureDebut, ?string $date): ?Carbon
    {
        if ($heureDebut !== null) {
            try {
                return Carbon::parse($heureDebut);
            } catch (\Throwable) {
                // Fallback sur la date seule ci-dessous.
            }
        }

        if ($date !== null) {
            try {
                return Carbon::parse($date)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
