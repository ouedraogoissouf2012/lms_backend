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
        Schema::create('forum_topics', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('lesson_id')->nullable()->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('matiere_id')->nullable()->comment('ID matière KLASSCI');
            $table->unsignedBigInteger('classe_id')->nullable()->comment('ID classe KLASSCI');

            // Contenu
            $table->string('title');
            $table->text('content');

            // Métadonnées
            $table->enum('status', ['open', 'closed', 'pinned'])->default('open');
            $table->boolean('is_resolved')->default(false);
            $table->integer('views_count')->default(0);

            // Statistiques
            $table->integer('posts_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('user_id');
            $table->index('lesson_id');
            $table->index('matiere_id');
            $table->index('classe_id');
            $table->index('status');
            $table->index(['status', 'last_activity_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forum_topics');
    }
};
