<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #567 — la suppression d'une institution devient LOGIQUE (soft delete).
 *
 * `InstitutionCrudService::softDelete()` faisait un DELETE physique alors que son
 * nom et le message client annonçaient une suppression douce : perte irréversible
 * de la config du tenant (URL/token KLASSCI, branding, settings) et orphelins
 * massifs (`institution_id` sans FK). On ajoute `deleted_at` (indexé) pour activer
 * le trait SoftDeletes sur Institution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->softDeletes();       // deleted_at TIMESTAMP NULL
            $table->index('deleted_at'); // filtrage rapide du SoftDeletingScope
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};
