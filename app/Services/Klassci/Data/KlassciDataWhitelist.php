<?php

declare(strict_types=1);

namespace App\Services\Klassci\Data;

/**
 * Filtre un payload KLASSCI (potentiellement compromis) avant sa mise en cache
 * dans `users.klassci_data` ou son exposition au frontend.
 *
 * ## Issue #477 — défense en profondeur (§1.2)
 *
 * `klassci_data` est un cache display informationnel, écrasé en bloc à partir du
 * payload `auth/me` / `auth/login`. Une instance KLASSCI compromise peut y pousser
 * n'importe quoi (`is_admin`, `permissions`, structure arbitraire). Ce collaborateur
 * restreint le blob à une **liste blanche stricte** de clés d'affichage, tout en
 * **préservant** les clés LMS internes `_lms_*` (namespace réservé, jamais injectable
 * par KLASSCI).
 *
 * Point d'application UNIQUE (DRY) des 3 vecteurs : sign-up, re-sync 24h, réponse
 * live `/auth/me`. Fonction pure, sans état, sans dépendance (aucune Facade, §1.6 D).
 *
 * ⚠️ NE JAMAIS ajouter à {@see self::ALLOWED_DISPLAY_KEYS} une clé lue pour de
 * l'AUTORISATION — l'autorisation lit les colonnes dédiées (`role`,
 * `klassci_enseignant_id`), jamais ce blob (cf. docblock {@see \App\Casts\KlassciData}).
 *
 * @see .claude/specs/klassci-data-display-whitelist/design.md §3, §6.1
 */
final class KlassciDataWhitelist
{
    /**
     * Liste blanche stricte des clés d'affichage KLASSCI conservées dans le blob.
     * Toute clé absente d'ici est droppée. `enseignant_id` est consommée par
     * `BackfillEnseignantIdCommand` (backfill/seed, jamais autorisation runtime).
     *
     * @var list<string>
     */
    public const ALLOWED_DISPLAY_KEYS = ['id', 'nom', 'name', 'prenom', 'photo', 'role', 'enseignant_id'];

    /** Préfixe réservé LMS : les clés `_lms_*` existantes sont toujours préservées. */
    public const LMS_RESERVED_PREFIX = '_lms_';

    /**
     * Restreint `$payload` à la liste blanche d'affichage, puis ré-applique les
     * clés `_lms_*` de `$existing`. Un `_lms_*` du payload ne peut jamais écraser
     * celui de `$existing` (namespace réservé LMS).
     *
     * @param  array<string, mixed>  $payload   Payload KLASSCI brut (data.user).
     * @param  array<string, mixed>  $existing  Blob klassci_data courant (pour préserver les _lms_*).
     * @return array<string, mixed>
     */
    public function filter(array $payload, array $existing = []): array
    {
        // 1. Projection d'affichage : ne garder que les clés whitelistées.
        $filtered = array_intersect_key($payload, array_flip(self::ALLOWED_DISPLAY_KEYS));

        // 2. Purge défensive : aucune clé _lms_* ne peut venir du payload KLASSCI.
        //    (ALLOWED_DISPLAY_KEYS n'en contient aucune ; garde explicite de l'invariant.)
        foreach (array_keys($filtered) as $key) {
            if (str_starts_with($key, self::LMS_RESERVED_PREFIX)) {
                unset($filtered[$key]);
            }
        }

        // 3. Ré-application : les _lms_* existantes (LMS interne) sont préservées.
        foreach ($existing as $key => $value) {
            if (str_starts_with($key, self::LMS_RESERVED_PREFIX)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
