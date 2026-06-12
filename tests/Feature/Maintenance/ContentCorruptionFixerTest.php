<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Institution;
use App\Models\User;
use App\Services\Maintenance\ContentCorruptionFixer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de la correction des contenus corrompus (#231).
 *
 * Vérifie : détection par signature, dry-run sans écriture, correction réelle
 * avec backup, idempotence, et NON-altération du contenu sain (faux positifs).
 *
 * @see app/Services/Maintenance/ContentCorruptionFixer.php
 */
final class ContentCorruptionFixerTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $user;
    private ContentCorruptionFixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        $this->fixer = app(ContentCorruptionFixer::class);
    }

    /**
     * Crée un topic dont le `content` est corrompu (JSON du payload entier),
     * en contournant l'application (insert direct) pour simuler la donnée prod.
     */
    private function corruptedTopic(string $realContent): ForumTopic
    {
        $topic = ForumTopic::factory()->create([
            'institution_id' => $this->institution->id,
            'user_id' => $this->user->id,
        ]);

        $corrupted = json_encode(['title' => $topic->title, 'content' => $realContent]);
        DB::table('forum_topics')->where('id', $topic->id)->update(['content' => $corrupted]);

        return $topic->refresh();
    }

    public function test_dry_run_detects_but_does_not_write(): void
    {
        $topic = $this->corruptedTopic('Le vrai contenu de la discussion.');
        $before = DB::table('forum_topics')->where('id', $topic->id)->value('content');

        $report = $this->fixer->run(apply: false);

        // Détecté comme corrompu, mais aucune écriture.
        $this->assertSame(1, $report['forum_topics']['corrupted']);
        $this->assertSame(0, $report['forum_topics']['fixed']);
        $this->assertSame(
            $before,
            DB::table('forum_topics')->where('id', $topic->id)->value('content'),
            'Le dry-run ne doit RIEN écrire'
        );
        $this->assertDatabaseCount('content_corruption_backups', 0);
    }

    public function test_apply_corrects_content_and_creates_backup(): void
    {
        $real = 'Le vrai contenu de la discussion à restaurer.';
        $topic = $this->corruptedTopic($real);

        $report = $this->fixer->run(apply: true);

        $this->assertSame(1, $report['forum_topics']['fixed']);

        // Le content est restauré à sa vraie valeur.
        $this->assertSame(
            $real,
            DB::table('forum_topics')->where('id', $topic->id)->value('content')
        );

        // L'original est sauvegardé pour réversibilité.
        $this->assertDatabaseHas('content_corruption_backups', [
            'table_name' => 'forum_topics',
            'row_id' => $topic->id,
            'corrected_content' => $real,
        ]);
    }

    public function test_healthy_content_is_never_touched(): void
    {
        // Un topic avec un contenu sain (texte normal) ne doit pas être modifié.
        $topic = ForumTopic::factory()->create([
            'institution_id' => $this->institution->id,
            'user_id' => $this->user->id,
            'content' => 'Un contenu parfaitement normal, non corrompu.',
        ]);

        // Et même un contenu qui COMMENCE par { mais n'est pas la signature
        // (JSON sans clé `content`) doit être épargné.
        $topicJsonLike = ForumTopic::factory()->create([
            'institution_id' => $this->institution->id,
            'user_id' => $this->user->id,
        ]);
        DB::table('forum_topics')->where('id', $topicJsonLike->id)
            ->update(['content' => '{"foo": "bar", "baz": 42}']);

        $report = $this->fixer->run(apply: true);

        $this->assertSame(0, $report['forum_topics']['fixed']);
        $this->assertSame(
            'Un contenu parfaitement normal, non corrompu.',
            DB::table('forum_topics')->where('id', $topic->id)->value('content')
        );
        $this->assertSame(
            '{"foo": "bar", "baz": 42}',
            DB::table('forum_topics')->where('id', $topicJsonLike->id)->value('content')
        );
    }

    public function test_is_idempotent_second_run_fixes_nothing(): void
    {
        $this->corruptedTopic('Contenu à corriger une seule fois.');

        $first = $this->fixer->run(apply: true);
        $this->assertSame(1, $first['forum_topics']['fixed']);

        // Après correction, le content est sain → plus rien à corriger.
        $second = $this->fixer->run(apply: true);
        $this->assertSame(0, $second['forum_topics']['corrupted']);
        $this->assertSame(0, $second['forum_topics']['fixed']);
        $this->assertDatabaseCount('content_corruption_backups', 1);
    }

    public function test_corrects_forum_posts_too(): void
    {
        $topic = ForumTopic::factory()->create([
            'institution_id' => $this->institution->id,
            'user_id' => $this->user->id,
        ]);
        $post = ForumPost::factory()->create([
            'topic_id' => $topic->id,
            'institution_id' => $this->institution->id,
            'user_id' => $this->user->id,
        ]);
        DB::table('forum_posts')->where('id', $post->id)
            ->update(['content' => json_encode(['content' => 'Réponse réelle du post.'])]);

        $report = $this->fixer->run(apply: true);

        $this->assertSame(1, $report['forum_posts']['fixed']);
        $this->assertSame(
            'Réponse réelle du post.',
            DB::table('forum_posts')->where('id', $post->id)->value('content')
        );
    }

    public function test_command_dry_run_then_apply(): void
    {
        $this->corruptedTopic('Contenu via la commande artisan.');

        // Dry-run : détecte, n'écrit pas.
        $this->artisan('content:fix-corruption')
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);
        $this->assertDatabaseCount('content_corruption_backups', 0);

        // Apply : corrige.
        $this->artisan('content:fix-corruption --apply')
            ->assertExitCode(0);
        $this->assertDatabaseCount('content_corruption_backups', 1);
    }
}
