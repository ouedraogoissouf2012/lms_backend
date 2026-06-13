<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit immuable (#215 — SOC2/GDPR).
 *
 * Trace « qui a fait quoi, quand, depuis où » sur les opérations sensibles
 * (CREATE/UPDATE/DELETE des modèles académiques) et les événements
 * d'authentification (login / logout / failed login).
 *
 * Append-only : aucune route ne met à jour ou supprime un audit log ; seule
 * la commande de rétention `audit:purge` élague au-delà du seuil configuré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Acteur (null possible : échec de login avant résolution, ou job système).
            $table->unsignedBigInteger('user_id')->nullable();

            // Tenant (null = supradmin / contexte sans institution résolue).
            $table->unsignedBigInteger('institution_id')->nullable();

            // Action normalisée : create|update|delete|view|export|import|login|logout|login_failed
            $table->string('action', 32);

            // Cible : type morphologique court + id (null pour les events auth).
            $table->string('auditable_type', 64)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            // Diff (JSON) : état avant / après. Null selon l'action.
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            // Provenance.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('user_id');
            $table->index('institution_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
