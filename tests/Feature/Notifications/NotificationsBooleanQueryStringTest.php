<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Http\Requests\ListNotificationsRequest;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `GET /notifications` doit accepter un booléen tel qu'une URL peut le porter.
 *
 * ## Le défaut, mesuré dans le navigateur le 2026-09-09
 *
 * À CHAQUE chargement du tableau de bord, le frontend émet
 * `GET /api/notifications?page=1&per_page=5&unread_only=false` et reçoit un
 * **422** :
 *
 *     {"success":false,"message":"Validation failed",
 *      "errors":{"unread_only":["The unread only field must be true or false."]}}
 *
 * La liste des notifications est donc cassée en permanence, silencieusement —
 * l'écran affiche simplement une liste vide.
 *
 * ## La cause
 *
 * Une chaîne de requête ne peut transporter QUE du texte. `unread_only=false`
 * arrive donc comme la chaîne `'false'`. Or la règle `boolean` de Laravel
 * n'accepte que `[true, false, 0, 1, '0', '1']`
 * ({@see \Illuminate\Validation\Concerns\ValidatesAttributes::validateBoolean()}) :
 * `'false'` et `'true'` en sont EXCLUS.
 *
 * Exiger `unread_only=0` d'un client HTTP est un contrat que le transport ne
 * peut pas honorer naturellement — `axios`, `fetch`, `curl` et les SDK générés
 * sérialisent tous un booléen JavaScript en `'true'`/`'false'`.
 *
 * ## Pourquoi la normalisation, et pas `$request->boolean()`
 *
 * `boolean()` rend `false` pour TOUT ce qu'il ne reconnaît pas : `unread_only=nawak`
 * deviendrait silencieusement `false` au lieu d'un 422. On ne normalise donc que
 * les formes textuelles RECONNUES, et tout le reste continue de tomber sur la
 * règle `boolean` — le contrat de validation est préservé, pas contourné.
 */
#[CoversClass(ListNotificationsRequest::class)]
final class NotificationsBooleanQueryStringTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();
        $this->user = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);
    }

    /**
     * LE cas réel : la forme que tout client HTTP émet.
     */
    public function test_the_form_every_http_client_sends_is_accepted(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/notifications?page=1&per_page=5&unread_only=false')
            ->assertStatus(200);
    }

    /**
     * Les six formes textuelles qu'une URL peut porter pour un booléen.
     *
     * @param  string  $valeur  Ce qui transite réellement dans la chaîne de requête.
     */
    #[DataProvider('formesTextuellesDunBooleen')]
    public function test_a_textual_boolean_is_accepted(string $valeur): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/notifications?unread_only='.$valeur)
            ->assertStatus(200);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formesTextuellesDunBooleen(): array
    {
        return [
            "'false'" => ['false'],
            "'true'" => ['true'],
            "'0'" => ['0'],
            "'1'" => ['1'],
            "'FALSE' (casse indifferente)" => ['FALSE'],
            "'True' (casse indifferente)" => ['True'],
        ];
    }

    /**
     * Le garde-fou de la normalisation : elle ne doit PAS avaler n'importe quoi.
     *
     * C'est ce test qui distingue « on accepte le transport » de « on accepte
     * tout ». Sans lui, remplacer la règle par `$request->boolean()` passerait —
     * en transformant silencieusement une faute de frappe en `false`.
     */
    public function test_an_unrecognised_value_is_still_refused(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/notifications?unread_only=nawak')
            ->assertStatus(422)
            ->assertJsonPath('errors.unread_only.0', fn (mixed $m): bool => is_string($m));
    }

    /**
     * Le filtre doit rester FONCTIONNEL après normalisation : accepter la valeur
     * sans l'appliquer serait un correctif qui ne corrige rien.
     */
    public function test_the_filter_still_filters_after_normalisation(): void
    {
        \App\Models\Notification::factory()->create([
            'user_id' => $this->user->id,
            'institution_id' => $this->user->institution_id,
            'read_at' => now(),
        ]);
        \App\Models\Notification::factory()->create([
            'user_id' => $this->user->id,
            'institution_id' => $this->user->institution_id,
            'read_at' => null,
        ]);

        Sanctum::actingAs($this->user);

        $toutes = $this->getJson('/api/notifications?unread_only=false')->json('data');
        $nonLues = $this->getJson('/api/notifications?unread_only=true')->json('data');

        self::assertCount(2, $toutes, 'unread_only=false doit rendre TOUTES les notifications');
        self::assertCount(1, $nonLues, 'unread_only=true doit ne rendre que les non lues');
    }
}
