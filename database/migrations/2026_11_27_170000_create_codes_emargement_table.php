<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1680 — le code d'émargement d'une séance en ligne (ARRÊT 1 validé le 27/11).
 *
 * - `code_hash` : HMAC-SHA256 du code NORMALISÉ, avec la clé de l'application. Déterministe, pour retrouver le code
 *   saisi sans le garder en clair ; le clair n'est rendu qu'une fois, à la génération.
 * - `signatures` : compteur des signatures NOUVELLES de ce code (un rejeu ne compte pas). Une ligne de présence ne dit
 *   pas quel code l'a signée ; le compteur donne des statistiques exactes par code sans toucher `esbtp_attendance`.
 * - Le code est PAR SÉANCE : le code de journée est retiré (décision de la superviseure).
 *
 * L'école en RESTRICT, comme toute table déclarée dans `tenancy.institution_scoped_tables` (la purge d'une école
 * refuse tant qu'il reste des lignes) ; la séance en cascade (un code ne survit pas à sa séance) ; l'auteur supprimé
 * laisse le code, sans auteur. Index de lecture : `(institution_id, code_hash)` pour la signature, `seance_id` pour la liste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codes_emargement', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('seance_id')->constrained('seances')->cascadeOnDelete();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('code_hash', 64);
            $table->timestamp('expire_le');
            $table->timestamp('revoque_le')->nullable();
            $table->unsignedSmallInteger('retard_apres_minutes')->nullable();
            $table->unsignedInteger('signatures')->default(0);
            $table->timestamps();

            $table->index(['institution_id', 'code_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codes_emargement');
    }
};
