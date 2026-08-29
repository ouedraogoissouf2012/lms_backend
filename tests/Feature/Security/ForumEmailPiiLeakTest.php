<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #544 : l'email d'un autre utilisateur ne doit jamais apparaître dans
 * les réponses forum, même si id/name/role restent nécessaires à
 * l'attribution des messages.
 */
final class ForumEmailPiiLeakTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHOR_EMAIL = 'auteur-distinctif@example.test';

    private Institution $institution;

    private User $author;

    private User $viewer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();

        $this->author = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
            'email' => self::AUTHOR_EMAIL,
        ]);

        $this->viewer = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);

        $this->admin = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'admin',
        ]);
    }

    public function test_index_does_not_expose_topic_author_email(): void
    {
        ForumTopic::factory()->create([
            'institution_id' => $this->institution->id,
            'user_id' => $this->author->id,
        ]);

        Sanctum::actingAs($this->viewer);

        $response = $this->getJson('/api/forum/topics');

        $response->assertStatus(200);
        $this->assertStringNotContainsString(self::AUTHOR_EMAIL, $response->getContent());
        $this->assertStringContainsString($this->author->name, $response->getContent());
    }

    public function test_show_does_not_expose_topic_or_post_author_email(): void
    {
        $topic = ForumTopic::factory()->create([
            'institution_id' => $this->institution->id,
            'user_id' => $this->author->id,
        ]);

        Sanctum::actingAs($this->viewer);

        $response = $this->getJson("/api/forum/topics/{$topic->id}");

        $response->assertStatus(200);
        $this->assertStringNotContainsString(self::AUTHOR_EMAIL, $response->getContent());
        $this->assertStringContainsString($this->author->name, $response->getContent());
    }

    public function test_store_does_not_expose_creating_user_email_in_response(): void
    {
        Sanctum::actingAs($this->author);

        $response = $this->postJson('/api/forum/topics', [
            'title' => 'Question sur le cours',
            'content' => 'Contenu de la question, assez long pour passer la validation.',
        ]);

        $response->assertStatus(201);
        $this->assertStringNotContainsString(self::AUTHOR_EMAIL, $response->getContent());
    }

    public function test_store_post_does_not_expose_replying_user_email_in_response(): void
    {
        $topic = ForumTopic::factory()->create([
            'institution_id' => $this->institution->id,
            'user_id' => $this->viewer->id,
        ]);

        Sanctum::actingAs($this->author);

        $response = $this->postJson("/api/forum/topics/{$topic->id}/posts", [
            'content' => 'Réponse assez longue pour passer la validation du formulaire.',
        ]);

        $response->assertStatus(201);
        $this->assertStringNotContainsString(self::AUTHOR_EMAIL, $response->getContent());
    }

    public function test_update_topic_by_admin_does_not_expose_original_authors_email(): void
    {
        $topic = ForumTopic::factory()->create([
            'institution_id' => $this->institution->id,
            'user_id' => $this->author->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/forum/topics/{$topic->id}", [
            'title' => 'Titre modifié par un admin',
        ]);

        $response->assertStatus(200);
        $this->assertStringNotContainsString(self::AUTHOR_EMAIL, $response->getContent());
        $this->assertStringContainsString($this->author->name, $response->getContent());
    }

    public function test_update_post_by_admin_does_not_expose_original_authors_email(): void
    {
        $topic = ForumTopic::factory()->create([
            'institution_id' => $this->institution->id,
            'user_id' => $this->viewer->id,
        ]);
        $post = ForumPost::factory()->create([
            'institution_id' => $this->institution->id,
            'topic_id' => $topic->id,
            'user_id' => $this->author->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/forum/posts/{$post->id}", [
            'content' => 'Contenu modifié par un admin, assez long pour la validation.',
        ]);

        $response->assertStatus(200);
        $this->assertStringNotContainsString(self::AUTHOR_EMAIL, $response->getContent());
        $this->assertStringContainsString($this->author->name, $response->getContent());
    }

    public function test_mark_as_solution_by_topic_owner_does_not_expose_post_authors_email(): void
    {
        // Autorisation vérifiée contre le propriétaire du TOPIC, pas du post
        // (cf. MarkPostAsSolutionRequest) : le viewer possède le topic, author
        // a répondu — c'est bien l'email de l'auteur du POST qui est en jeu.
        $topic = ForumTopic::factory()->create([
            'institution_id' => $this->institution->id,
            'user_id' => $this->viewer->id,
        ]);
        $post = ForumPost::factory()->create([
            'institution_id' => $this->institution->id,
            'topic_id' => $topic->id,
            'user_id' => $this->author->id,
        ]);

        Sanctum::actingAs($this->viewer);

        $response = $this->postJson("/api/forum/posts/{$post->id}/solution");

        $response->assertStatus(200);
        $this->assertStringNotContainsString(self::AUTHOR_EMAIL, $response->getContent());
        $this->assertStringContainsString($this->author->name, $response->getContent());
    }
}
