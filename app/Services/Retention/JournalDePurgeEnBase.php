<?php

declare(strict_types=1);

namespace App\Services\Retention;

use App\Models\Institution;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;

/**
 * `purge_log`, une ligne par élément détruit (#719, B8).
 *
 * ## Le journal survit à son sujet
 *
 * `purge_log.institution_id` pointe vers `institutions` en ON DELETE SET NULL, et la table est
 * une EXCEPTION déclarée de `config/tenancy.php`. En RESTRICT, purger une école serait bloqué
 * par son propre journal ; en SET NULL, la ligne reste, et `subject_key` garde l'identifiant.
 *
 * ## Aucune donnée personnelle
 *
 * `subject_key` est la clé de la ligne, `subject_type` sa table, `reason` le libellé de la
 * politique. Rien de ce que la ligne contenait (nom, courriel, slug) n'entre ici.
 */
final class JournalDePurgeEnBase implements JournalDePurge
{
    /** Les purges ne se lancent qu'à la main, par `purge:run` : jamais planifiées (#690). */
    private const ACTEUR = 'console:purge:run';

    public function __construct(private readonly DatabaseManager $db) {}

    public function consigner(RetentionPolicy $politique, Model $ligne, CarbonInterface $cutoff): void
    {
        $this->db->connection()->table('purge_log')->insert([
            'institution_id' => $this->institutionDe($ligne),
            'subject_type' => $ligne->getTable(),
            'subject_key' => $politique->describe($ligne),
            'category' => $politique->key(),
            'rows_deleted' => 1,
            'executed_at' => now(),
            'actor' => self::ACTEUR,
            'reason' => $politique->label(),
            'retention_rule' => 'éligible avant le '.$cutoff->toIso8601String(),
        ]);
    }

    private function institutionDe(Model $ligne): ?int
    {
        $id = $ligne instanceof Institution ? $ligne->getKey() : $ligne->getAttribute('institution_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
