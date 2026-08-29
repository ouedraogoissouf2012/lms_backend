<?php

declare(strict_types=1);

use App\Services\Integrity\ForeignKeyCandidate;
use App\Services\Integrity\OrphanRowPurger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #541 — `esbtp_attendance.seance_id` / `.user_id` étaient de simples
 * `unsignedBigInteger` indexés (migration 2025_10_25_190916:18-19) : rien en base
 * ne garantissait que la séance ou le participant existaient encore. Chaque
 * suppression physique d'un utilisateur laissait derrière elle des présences
 * fantômes, comptabilisées par les rapports.
 *
 * ## ON DELETE CASCADE (et non RESTRICT)
 *
 * Une présence n'a aucun sens sans sa séance ni son participant : la cascade
 * empêche le défaut de réapparaître. Elle diffère volontairement du `RESTRICT`
 * retenu par #583 sur `institution_id`, où l'enjeu inverse était d'interdire
 * qu'une suppression d'institution vide 30 tables. `users` (#566) et `seances`
 * sont par ailleurs soft-deletables : la cascade ne se déclenche qu'à la purge
 * physique, jamais lors d'une suppression métier ordinaire.
 *
 * ## Orphelins préexistants
 *
 * Ils sont archivés intégralement dans `orphan_row_archive` PUIS retirés — la
 * pose de la FK échouerait sinon (`errno 1452` sous MySQL). Contrairement à un
 * `institution_id` orphelin, rattachable à un tenant par décision humaine (#583),
 * une présence dont la séance ou l'utilisateur n'existe plus n'est rattachable à
 * rien : bloquer la migration n'offrirait aucune issue autre que la suppression.
 * On archive donc, ce qui reste réversible tout en débloquant.
 *
 * @see App\Services\Integrity\OrphanRowPurger
 */
return new class extends Migration
{
    /** @var list<array{column: string, references: string}> */
    private const FOREIGN_KEYS = [
        ['column' => 'seance_id', 'references' => 'seances'],
        ['column' => 'user_id', 'references' => 'users'],
    ];

    public function up(): void
    {
        $purger = app(OrphanRowPurger::class);

        foreach (self::FOREIGN_KEYS as $foreignKey) {
            $purger->purge(new ForeignKeyCandidate(
                'esbtp_attendance',
                $foreignKey['column'],
                $foreignKey['references'],
            ));

            // Idempotence : sous MySQL chaque ADD FOREIGN KEY est un DDL à commit
            // implicite — une relance après échec partiel ne doit pas retomber sur
            // « duplicate foreign key ».
            if ($this->hasForeignKeyOn($foreignKey['column'])) {
                continue;
            }

            Schema::table('esbtp_attendance', function (Blueprint $table) use ($foreignKey): void {
                $table->foreign($foreignKey['column'])
                    ->references('id')->on($foreignKey['references'])
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::FOREIGN_KEYS as $foreignKey) {
            if (! $this->hasForeignKeyOn($foreignKey['column'])) {
                continue;
            }

            Schema::table('esbtp_attendance', function (Blueprint $table) use ($foreignKey): void {
                $table->dropForeign([$foreignKey['column']]);
            });
        }
    }

    private function hasForeignKeyOn(string $column): bool
    {
        foreach (Schema::getForeignKeys('esbtp_attendance') as $foreignKey) {
            /** @var array{columns: list<string>} $foreignKey */
            if ($foreignKey['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
};
