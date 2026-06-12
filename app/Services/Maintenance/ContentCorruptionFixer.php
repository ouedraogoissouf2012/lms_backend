<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * Détecte et corrige le champ `content` corrompu par le bug `$this->content`
 * (#212 / PR #230) sur forum_topics, forum_posts, lessons, chapters (#231).
 *
 * ## Signature de la corruption
 *
 * Le content corrompu vaut `json_encode($payloadValidé)` — donc une chaîne qui
 * `json_decode` en **tableau associatif contenant une clé `content`**. Le vrai
 * contenu est `json_decode($corrompu)['content']`. Cette signature à double
 * critère (JSON valide + clé `content` présente) minimise les faux positifs ;
 * le backup systématique les rend de toute façon réversibles.
 *
 * ## Sécurité d'exécution
 *
 * - Dry-run par défaut côté commande : ce service ne mute QUE si `$apply=true`.
 * - Chaque correction sauvegarde l'original dans `content_corruption_backups`
 *   AVANT écrasement (réversibilité).
 * - Idempotent : une row déjà sauvegardée (contrainte unique) n'est pas
 *   re-traitée, et un content déjà sain ne matche pas la signature.
 *
 * DI strict §1.6 : DatabaseManager + LoggerInterface injectés.
 */
final class ContentCorruptionFixer
{
    /**
     * Tables porteuses d'un champ `content` exposé au bug.
     *
     * @var list<string>
     */
    private const TARGET_TABLES = [
        'forum_topics',
        'forum_posts',
        'lessons',
        'chapters',
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Analyse (et corrige si `$apply`) toutes les tables cibles.
     *
     * @return array<string, array{scanned:int, corrupted:int, fixed:int}>
     *         Rapport par table.
     */
    public function run(bool $apply): array
    {
        $report = [];

        foreach (self::TARGET_TABLES as $table) {
            $report[$table] = $this->processTable($table, $apply);
        }

        return $report;
    }

    /**
     * @return array{scanned:int, corrupted:int, fixed:int}
     */
    private function processTable(string $table, bool $apply): array
    {
        $scanned = 0;
        $corrupted = 0;
        $fixed = 0;

        // Curseur pour ne pas charger toute la table en mémoire (scale prod).
        $this->db->table($table)
            ->select('id', 'content')
            ->whereNotNull('content')
            ->where('content', 'like', '{%')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $apply, &$scanned, &$corrupted, &$fixed): void {
                foreach ($rows as $row) {
                    $scanned++;

                    $clean = $this->extractCorrectedContent((string) $row->content);
                    if ($clean === null) {
                        continue; // pas la signature de corruption
                    }

                    $corrupted++;

                    if (! $apply) {
                        continue;
                    }

                    $this->backupAndFix($table, (int) $row->id, (string) $row->content, $clean);
                    $fixed++;
                }
            });

        return ['scanned' => $scanned, 'corrupted' => $corrupted, 'fixed' => $fixed];
    }

    /**
     * Retourne le contenu corrigé si `$stored` porte la signature de corruption,
     * sinon `null`.
     */
    private function extractCorrectedContent(string $stored): ?string
    {
        $decoded = json_decode($stored, true);

        if (! is_array($decoded) || ! array_key_exists('content', $decoded)) {
            return null;
        }

        $inner = $decoded['content'];

        // La valeur interne doit être une string (le vrai contenu). Un non-string
        // signale un payload atypique → on s'abstient (sécurité).
        return is_string($inner) ? $inner : null;
    }

    /**
     * Sauvegarde l'original puis écrit le contenu corrigé, en transaction.
     */
    private function backupAndFix(string $table, int $id, string $original, string $corrected): void
    {
        $this->db->transaction(function () use ($table, $id, $original, $corrected): void {
            $this->db->table('content_corruption_backups')->insert([
                'table_name' => $table,
                'row_id' => $id,
                'original_content' => $original,
                'corrected_content' => $corrected,
                'fixed_at' => now(),
            ]);

            $this->db->table($table)
                ->where('id', $id)
                ->update(['content' => $corrected]);
        });

        $this->logger->info('Content corrompu corrigé', [
            'table' => $table,
            'row_id' => $id,
        ]);
    }
}
