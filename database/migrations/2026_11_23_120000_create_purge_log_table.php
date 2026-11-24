<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #719 (B8) — le journal de conformité des purges.
 *
 * Une ligne par élément détruit : un identifiant (`subject_key`), jamais un contenu.
 * `orphan_row_archive` stocke la ligne intégrale et ne peut donc pas servir de journal : ce
 * serait recréer la donnée effacée.
 *
 * `institution_id` est nullable en ON DELETE SET NULL, et la table est une EXCEPTION déclarée
 * de `config/tenancy.php` : le journal doit survivre à la purge de son sujet. En RESTRICT,
 * purger une école serait bloqué par son propre journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purge_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->string('subject_type', 64);
            $table->string('subject_key', 64);
            $table->string('category', 64);
            $table->unsignedInteger('rows_deleted');
            $table->timestamp('executed_at');
            $table->string('actor', 64);
            $table->string('reason', 255);
            $table->string('retention_rule', 255);

            $table->index(['category', 'executed_at']);
            $table->index(['subject_type', 'subject_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purge_log');
    }
};
