<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade')->index();

            // Type de notification
            $table->string('type')->index(); // lesson_published, forum_reply, quiz_available, grade_received, etc.

            // Contenu de la notification
            $table->string('title');
            $table->text('message');

            // Données contextuelles (JSON)
            $table->json('data')->nullable(); // { lesson_id: 1, quiz_id: 2, etc. }

            // Statut
            $table->timestamp('read_at')->nullable()->index();

            $table->timestamps();

            // Index composites
            $table->index(['user_id', 'read_at']); // Pour récupérer les notifications non lues
            $table->index(['user_id', 'created_at']); // Pour trier par date
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
