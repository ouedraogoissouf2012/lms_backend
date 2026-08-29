<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #549 — seance_recordings n'existait pas lors du backfill institution_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('seance_recordings', 'institution_id')) {
            Schema::table('seance_recordings', function (Blueprint $table): void {
                $table->unsignedBigInteger('institution_id')->nullable()->after('id');
                $table->index('institution_id');
            });
        }

        foreach (DB::table('seance_recordings')->select('id', 'seance_id')->orderBy('id')->cursor() as $row) {
            $institutionId = DB::table('seances')->where('id', $row->seance_id)->value('institution_id');
            DB::table('seance_recordings')->where('id', $row->id)->update([
                'institution_id' => $institutionId,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('seance_recordings', 'institution_id')) {
            Schema::table('seance_recordings', function (Blueprint $table): void {
                $table->dropIndex(['institution_id']);
                $table->dropColumn('institution_id');
            });
        }
    }
};
