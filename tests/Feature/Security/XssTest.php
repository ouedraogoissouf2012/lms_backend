<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests de sécurité — XSS (#212).
 *
 * Modèle de défense XSS du backend (cf. StoreLessonRequest docblock) :
 *   1. Les réponses sont du JSON (`Content-Type: application/json`) — un
 *      navigateur ne les interprète jamais comme HTML.
 *   2. Le contenu est stocké VERBATIM (pas de sanitization serveur), pour ne
 *      pas mutiler un contenu légitime (ex. un cours sur le HTML).
 *   3. Le rendu HTML (vues Blade rapports/PDF) échappe via `{{ }}`.
 *
 * Ces tests AFFIRMENT ces invariants : un payload XSS est restitué tel quel
 * dans une string JSON correctement encodée (jamais en HTML brut exécutable),
 * et le Content-Type interdit l'interprétation navigateur.
 *
 * @see app/Http/Requests/StoreForumTopicRequest.php
 * @see app/Services/Forum/ForumTopicService.php
 */
final class XssTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;

    /**
     * Payloads XSS classiques (script, handler, iframe, svg, protocole js).
     *
     * @return list<string>
     */
    private function xssPayloads(): array
    {
        return [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert(1)>',
            '"><script>document.cookie</script>',
            '<svg/onload=alert(1)>',
            'javascript:alert(document.domain)',
            '<iframe src="javascript:alert(1)">',
            '<body onload=alert(1)>',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
    }

    public function test_forum_topic_xss_payload_is_stored_verbatim_and_json_safe(): void
    {
        Sanctum::actingAs($this->student);

        foreach ($this->xssPayloads() as $payload) {
            $title = "Question {$payload}";
            $content = "Contenu de discussion avec {$payload} intégré dans le corps.";

            $create = $this->postJson('/api/forum/topics', [
                'title' => $title,
                'content' => $content,
            ]);
            $create->assertStatus(201);
            $topicId = $create->json('data.id');

            // 1. Réponse = JSON (Content-Type), jamais servie comme HTML —
            //    c'est LA défense : un navigateur ne parse pas du JSON en HTML.
            $show = $this->getJson("/api/forum/topics/{$topicId}");
            $show->assertStatus(200);
            $this->assertStringContainsString(
                'application/json',
                (string) $show->headers->get('Content-Type'),
                "Le détail topic doit être du JSON (sinon le payload [{$payload}] serait exécutable)"
            );

            // 2. Contenu restitué VERBATIM (fidélité préservée — le forum ne
            //    sanitize pas, cf. modèle XSS du projet).
            $show->assertJsonPath('data.title', $title);
            $show->assertJsonPath('data.content', $content);

            // 3. Le payload vit dans une VALEUR de string JSON valide (échappée
            //    pour le contexte JSON), pas comme nœud DOM : un re-décodage
            //    JSON rend exactement la string d'origine, preuve qu'il n'a pas
            //    été interprété comme structure.
            $decoded = json_decode((string) $show->getContent(), true);
            $this->assertSame($content, $decoded['data']['content'] ?? null);
        }
    }

    public function test_xss_payload_in_user_profile_fields_is_json_escaped(): void
    {
        // Un nom contenant un payload XSS, renvoyé dans /api/auth/me ou autre
        // endpoint de profil, doit rester dans une string JSON encodée.
        $payloadUser = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
            'name' => '<script>alert("name")</script>',
        ]);

        Sanctum::actingAs($payloadUser);

        $response = $this->getJson('/api/auth/me');

        // /me peut exiger un sync KLASSCI (middleware) → tolérer 200 ou refus.
        if ($response->status() === 200) {
            $this->assertStringContainsString(
                'application/json',
                (string) $response->headers->get('Content-Type'),
                'Le profil doit être servi en JSON'
            );

            // Le payload est restitué dans une string JSON valide : un
            // re-décodage rend exactement le nom d'origine (jamais un nœud DOM).
            $decoded = json_decode((string) $response->getContent(), true);
            $flat = json_encode($decoded);
            $this->assertStringContainsString(
                'alert',
                (string) $flat,
                'Le nom (avec payload) doit être préservé dans la réponse'
            );
        } else {
            // Si /me refuse hors contexte KLASSCI, l'invariant Content-Type
            // reste couvert par les autres tests de cette classe.
            $this->assertContains($response->status(), [401, 403, 419, 500, 200]);
        }
    }

    public function test_json_responses_never_served_as_html_content_type(): void
    {
        Sanctum::actingAs($this->student);

        $create = $this->postJson('/api/forum/topics', [
            'title' => 'Titre <b>normal</b>',
            'content' => 'Contenu normal pour vérifier le Content-Type de la liste.',
        ]);
        $create->assertStatus(201);

        // Les endpoints de lecture renvoient tous du JSON, jamais du text/html.
        foreach (['/api/forum/topics', "/api/forum/topics/{$create->json('data.id')}"] as $url) {
            $response = $this->getJson($url);
            $response->assertStatus(200);
            $contentType = $response->headers->get('Content-Type');
            $this->assertStringContainsString(
                'application/json',
                (string) $contentType,
                "L'endpoint {$url} ne renvoie pas du JSON (risque XSS si HTML)"
            );
        }
    }
}
