<?php

declare(strict_types=1);

namespace App\Services\Donnees\Export;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Une section lue dans UNE table, déclarée plutôt qu'écrite (#1705) : les lignes où `colonneEleve` désigne l'élève,
 * dans son école (`institution_id`, filtré explicitement), triées par id. Les colonnes sont NOMMÉES une à une : rien
 * de ce qui n'est pas listé ne sort. Une jointure facultative ajoute un libellé lisible (le titre d'une leçon, d'une
 * séance).
 */
final class SectionParTable implements SectionDExport
{
    /**
     * @param  list<string>  $colonnes  colonnes de `$table`, exportées sous leur nom
     * @param  list<string>  $json  celles d'entre elles qui portent du JSON, décodé
     * @param  array{table: string, cle: string, colonnes: array<string, string>}|null  $jointure  la table jointe sur
     *                                                                                             `$table.{cle} = {table}.id`, et ses colonnes (alias => colonne)
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $cle,
        private readonly string $titre,
        private readonly string $table,
        private readonly string $colonneEleve,
        private readonly array $colonnes,
        private readonly array $json = [],
        private readonly ?array $jointure = null,
    ) {}

    public function cle(): string
    {
        return $this->cle;
    }

    public function titre(): string
    {
        return $this->titre;
    }

    public function lignes(User $eleve, int $ecole): array
    {
        $requete = $this->db->table($this->table)
            ->where("{$this->table}.{$this->colonneEleve}", $eleve->getKey())
            ->where("{$this->table}.institution_id", $ecole)
            ->orderBy("{$this->table}.id")
            ->select(array_map(fn (string $c): string => "{$this->table}.{$c}", $this->colonnes));
        $this->joindre($requete);

        return array_values($requete->get()->map(fn (object $ligne): array => $this->decoder((array) $ligne))->all());
    }

    private function joindre(Builder $requete): void
    {
        if ($this->jointure === null) {
            return;
        }
        ['table' => $table, 'cle' => $cle, 'colonnes' => $colonnes] = $this->jointure;
        $requete->leftJoin($table, "{$table}.id", '=', "{$this->table}.{$cle}");
        foreach ($colonnes as $alias => $colonne) {
            $requete->addSelect("{$table}.{$colonne} as {$alias}");
        }
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return array<string, mixed>
     */
    private function decoder(array $ligne): array
    {
        foreach ($this->json as $colonne) {
            if (is_string($ligne[$colonne] ?? null)) {
                $ligne[$colonne] = json_decode($ligne[$colonne], true);
            }
        }

        return $ligne;
    }
}
