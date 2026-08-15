<?php

declare(strict_types=1);

namespace Tests\Feature\Forum;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #566 — régression cross-file du soft delete.
 *
 * Un topic/post de forum est un dossier PRÉSERVÉ. Après l'ajout de `SoftDeletes`
 * sur User, une relation `belongsTo(User)` nue renverrait `null` pour un auteur
 * soft-deleted → `$topic->user->name` (ex. `StudentDashboardService::recentForumActivity`,
 * `ForumNotificationDispatcher`) lèverait une 500. Le correctif (`user()->withTrashed()`)
 * garde l'auteur affichable.
 *
 * @see app/Models/ForumTopic.php
 * @see app/Models/ForumPost.php
 * @see app/Services/Dashboard/StudentDashboardService.php:261
 */
final class ForumAuthorSurvivesSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_topic_author_name_remains_readable_after_soft_delete(): void
    {
        $institution = Institution::factory()->create();
        $author = User::factory()->create([
            'institution_id' => $institution->id,
            'name' => 'Auteur Supprimé',
        ]);
        $topic = ForumTopic::factory()->create([
            'user_id' => $author->id,
            'institution_id' => $institution->id,
        ]);

        $author->delete();

        // Sans withTrashed, ceci serait null → 500 au déréférencement ->name.
        $reloaded = ForumTopic::withoutGlobalScope('institution')->findOrFail($topic->id);
        $this->assertNotNull($reloaded->user);
        $this->assertSame('Auteur Supprimé', $reloaded->user->name);
    }

    public function test_post_author_name_remains_readable_after_soft_delete(): void
    {
        $institution = Institution::factory()->create();
        $author = User::factory()->create([
            'institution_id' => $institution->id,
            'name' => 'Répondeur Supprimé',
        ]);
        $post = ForumPost::factory()->create([
            'user_id' => $author->id,
            'institution_id' => $institution->id,
        ]);

        $author->delete();

        $reloaded = ForumPost::withoutGlobalScope('institution')->findOrFail($post->id);
        $this->assertNotNull($reloaded->user);
        $this->assertSame('Répondeur Supprimé', $reloaded->user->name);
    }
}
