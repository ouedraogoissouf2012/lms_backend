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
        Schema::create('forum_posts', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('topic_id')->constrained('forum_topics')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('forum_posts')->onDelete('cascade');

            // Contenu
            $table->text('content');

            // Métadonnées
            $table->boolean('is_solution')->default(false)->comment('Marque comme solution (pour topics de type question)');
            $table->boolean('is_edited')->default(false);
            $table->timestamp('edited_at')->nullable();

            // Réactions
            $table->integer('likes_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('topic_id');
            $table->index('user_id');
            $table->index('parent_id');
            $table->index(['topic_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forum_posts');
    }
};
