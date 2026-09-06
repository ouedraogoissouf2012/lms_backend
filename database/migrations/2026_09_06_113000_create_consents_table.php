<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #716 — consentements append-only. Aucune UPDATE/DELETE applicative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('seance_id')->nullable();
            $table->string('purpose', 32);
            $table->boolean('granted');
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'user_id', 'purpose', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
