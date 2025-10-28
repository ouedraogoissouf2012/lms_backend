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
        // Table des catégories du forum
        Schema::create('forum_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('slug')->unique();
            $table->string('icon')->nullable(); // Icône de la catégorie
            $table->string('color')->default('#3b82f6'); // Couleur de la catégorie

            // Lien optionnel avec une matière
            $table->unsignedBigInteger('matiere_id')->nullable();
            $table->unsignedBigInteger('classe_id')->nullable();

            // Ordre d'affichage
            $table->integer('order')->default(0);

            // Permissions
            $table->boolean('is_public')->default(true); // Visible par tous
            $table->boolean('is_restricted')->default(false); // Accès restreint

            $table->timestamps();
            $table->softDeletes();
        });

        // Table des sujets/topics du forum
        Schema::create('forum_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('forum_categories')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Auteur

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('content');

            // Métadonnées
            $table->enum('status', ['open', 'closed', 'locked'])->default('open');
            $table->enum('type', ['discussion', 'question', 'announcement', 'poll'])->default('discussion');
            $table->boolean('is_pinned')->default(false); // Épinglé en haut
            $table->boolean('is_resolved')->default(false); // Pour les questions
            $table->foreignId('resolved_by')->nullable()->constrained('users'); // Qui a marqué comme résolu

            // Statistiques
            $table->integer('views_count')->default(0);
            $table->integer('posts_count')->default(0);
            $table->timestamp('last_post_at')->nullable();
            $table->foreignId('last_post_user_id')->nullable()->constrained('users');

            // Tags optionnels (JSON)
            $table->json('tags')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'created_at']);
            $table->index('is_pinned');
            $table->index('status');
        });

        // Table des posts/réponses du forum
        Schema::create('forum_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('forum_topics')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Auteur
            $table->foreignId('parent_id')->nullable()->constrained('forum_posts')->onDelete('cascade'); // Pour les réponses imbriquées

            $table->text('content');

            // Métadonnées
            $table->boolean('is_solution')->default(false); // Marqué comme solution à une question
            $table->boolean('is_moderated')->default(false); // Modéré par un enseignant
            $table->text('moderation_reason')->nullable();

            // Statistiques
            $table->integer('likes_count')->default(0);

            // Édition
            $table->timestamp('edited_at')->nullable();
            $table->foreignId('edited_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['topic_id', 'created_at']);
            $table->index('is_solution');
        });

        // Table des likes sur les posts
        Schema::create('forum_post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('forum_posts')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);
        });

        // Table des abonnements aux sujets (notifications)
        Schema::create('forum_topic_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('forum_topics')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->boolean('notify_email')->default(false);
            $table->timestamps();

            $table->unique(['topic_id', 'user_id']);
        });

        // Table des vues de sujets (pour éviter le double comptage)
        Schema::create('forum_topic_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('forum_topics')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('ip_address')->nullable();
            $table->timestamp('viewed_at');

            $table->index(['topic_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forum_topic_views');
        Schema::dropIfExists('forum_topic_subscriptions');
        Schema::dropIfExists('forum_post_likes');
        Schema::dropIfExists('forum_posts');
        Schema::dropIfExists('forum_topics');
        Schema::dropIfExists('forum_categories');
    }
};
