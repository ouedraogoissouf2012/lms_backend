<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1306 — la table du broker de réinitialisation Laravel disparaît avec lui.
 *
 * Elle n'a jamais reçu de jeton : le « mot de passe oublié » n'en crée plus depuis #1277, et le
 * lien de réinitialisation est le jeton d'activation à usage unique (#808, ADR-803-02). Mesurée
 * vide en prod, en lecture seule, le 19/11 et le 23/11 ; la superviseure la remesure avant la
 * fusion.
 *
 * `down()` la recrée telle que `2026_09_12_000000` la laissait : clé `(email, institution_id)`,
 * institution en CASCADE. Les migrations passées ne sont pas modifiées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email');
            $table->unsignedBigInteger('institution_id');
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            $table->primary(['email', 'institution_id']);
            $table->foreign('institution_id')
                ->references('id')
                ->on('institutions')
                ->cascadeOnDelete();
        });
    }
};
