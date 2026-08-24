<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #540 — pose le filet BASE manquant sur les tentatives de knowledge-check.
 *
 * `knowledge_check_attempts` n'avait ni numéro de tentative ni contrainte
 * d'unicité : le quota `max_attempts` ne reposait que sur un `count()`
 * applicatif consulté au démarrage (qui, lui, ne persiste rien). Deux
 * soumissions concurrentes — ou même trois soumissions séquentielles —
 * dépassaient le quota sans qu'aucune couche ne s'y oppose.
 *
 * Trois temps, dans cet ordre impératif :
 *   1. ajout de la colonne (défaut 1, pour que les lignes existantes soient valides) ;
 *   2. backfill déterministe 1..n par couple (quiz, étudiant), ordre chronologique `id` ;
 *   3. pose de l'unique — après le backfill, sinon les doublons hérités le feraient échouer.
 *
 * Le nom de l'index est explicite : le nom généré par défaut ferait 71
 * caractères et dépasserait la limite de 64 de MySQL (l'erreur ne serait pas
 * visible sous SQLite, qui n'a pas cette limite).
 */
return new class extends Migration
{
    /** Nom explicite : le nom auto-généré dépasserait les 64 caractères de MySQL. */
    private const UNIQUE_INDEX = 'kc_attempts_user_attempt_unique';

    /** Taille de lot du backfill — borne l'empreinte mémoire sur une grosse table. */
    private const CHUNK = 500;

    public function up(): void
    {
        Schema::table('knowledge_check_attempts', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_number')
                ->default(1)
                ->after('user_id')
                ->comment('Numero de la tentative pour ce couple (quiz, etudiant)');
        });

        $this->backfillAttemptNumbers();

        Schema::table('knowledge_check_attempts', function (Blueprint $table): void {
            $table->unique(['knowledge_check_id', 'user_id', 'attempt_number'], self::UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_check_attempts', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
        });

        Schema::table('knowledge_check_attempts', function (Blueprint $table): void {
            $table->dropColumn('attempt_number');
        });
    }

    /**
     * Numérote les tentatives héritées : 1..n par couple (quiz, étudiant), dans
     * l'ordre chronologique d'insertion (`id` croissant).
     *
     * Écrit en PHP plutôt qu'en SQL : `ROW_NUMBER() OVER (PARTITION BY …)`
     * n'existe pas sous SQLite avant 3.25 et `UPDATE … JOIN` est une extension
     * MySQL. Une seule formulation doit passer sur les deux moteurs de la
     * matrice CI.
     */
    private function backfillAttemptNumbers(): void
    {
        $currentCouple = null;
        $counter = 0;

        DB::table('knowledge_check_attempts')
            ->select('id', 'knowledge_check_id', 'user_id')
            ->orderBy('knowledge_check_id')
            ->orderBy('user_id')
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($rows) use (&$currentCouple, &$counter): void {
                /** @var array<int, array<int, int>> $idsByNumber */
                $idsByNumber = [];

                foreach ($rows as $row) {
                    $couple = $row->knowledge_check_id . ':' . $row->user_id;
                    if ($couple !== $currentCouple) {
                        $currentCouple = $couple;
                        $counter = 0;
                    }

                    $idsByNumber[++$counter][] = (int) $row->id;
                }

                // Un UPDATE par valeur distincte de numéro (typiquement 1 à 5),
                // pas un par ligne : le backfill reste linéaire en lots, pas en N+1.
                foreach ($idsByNumber as $attemptNumber => $ids) {
                    DB::table('knowledge_check_attempts')
                        ->whereIn('id', $ids)
                        ->update(['attempt_number' => $attemptNumber]);
                }
            });
    }
};
