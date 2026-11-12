<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D72 (E2a) — une copie appartient à son auteur par `student_id`.
 *
 * `evaluation_submissions.klassci_etudiant_id` était NOT NULL, et l'unique
 * portait `(evaluation_id, klassci_etudiant_id, attempt)`. Un élève d'une
 * école créée depuis le LMS n'a pas d'identité KLASSCI : il ne pouvait pas
 * avoir de copie.
 *
 * - `klassci_etudiant_id` devient nullable.
 * - Un unique `(evaluation_id, student_id, attempt)` protège désormais les
 *   copies par leur auteur. L'ancien unique reste : il protège encore les
 *   lignes KLASSCI, et plusieurs NULL n'y entrent pas en collision (MySQL,
 *   SQLite).
 * - AVANT toute modification, la migration cherche les doublons existants et
 *   échoue avec leur liste, au lieu d'un 1062 opaque en production : rien
 *   n'est touché (le DDL de MySQL n'est pas transactionnel).
 * - `change()` redéclare tous les modificateurs (Laravel 11+) : le commentaire
 *   de colonne est conservé.
 */
return new class extends Migration
{
    private const UNIQUE = 'eval_sub_student_unique';

    public function up(): void
    {
        $doublons = $this->doublons();
        if ($doublons !== []) {
            throw new RuntimeException(
                'Copies en double sur (evaluation_id, student_id, attempt), à résoudre avant cette migration (D72) : '
                .implode(' ; ', $doublons).'.'
            );
        }

        Schema::table('evaluation_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('klassci_etudiant_id')->nullable()->comment('ID de l\'étudiant dans KLASSCI')->change();
        });

        if (! Schema::hasIndex('evaluation_submissions', self::UNIQUE)) {
            Schema::table('evaluation_submissions', function (Blueprint $table): void {
                $table->unique(['evaluation_id', 'student_id', 'attempt'], self::UNIQUE);
            });
        }
    }

    public function down(): void
    {
        $sansKlassci = DB::table('evaluation_submissions')->whereNull('klassci_etudiant_id')->count();
        if ($sansKlassci > 0) {
            throw new RuntimeException(
                "Retour arrière impossible : {$sansKlassci} copie(s) n'ont pas d'identité KLASSCI, "
                .'et la colonne redeviendrait NOT NULL (D72).'
            );
        }

        if (Schema::hasIndex('evaluation_submissions', self::UNIQUE)) {
            Schema::table('evaluation_submissions', function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE);
            });
        }

        Schema::table('evaluation_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('klassci_etudiant_id')->nullable(false)->comment('ID de l\'étudiant dans KLASSCI')->change();
        });
    }

    /**
     * Les triplets `(evaluation_id, student_id, attempt)` présents plus d'une
     * fois, `student_id` non nul.
     *
     * @return list<string>
     */
    public function doublons(): array
    {
        return DB::table('evaluation_submissions')
            ->select('evaluation_id', 'student_id', 'attempt', DB::raw('COUNT(*) as nombre'))
            ->whereNotNull('student_id')
            ->groupBy('evaluation_id', 'student_id', 'attempt')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('evaluation_id')
            ->get()
            ->map(static fn (object $ligne): string => sprintf(
                'évaluation %s, élève %s, tentative %s (%s fois)',
                $ligne->evaluation_id ?? '?',
                $ligne->student_id ?? '?',
                $ligne->attempt ?? '?',
                $ligne->nombre ?? '?',
            ))
            ->values()
            ->all();
    }
};
