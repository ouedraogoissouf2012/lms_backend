<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * `POST /api/admin/notifications/create` : le lien d'action n'est enregistré
 * que s'il est un chemin interne du LMS ou une adresse `https://`. Un refus est
 * un 422, et AUCUNE notification n'est écrite.
 *
 * Par la vraie route, vrai jeton porteur, dans les deux volets (école reliée à
 * KLASSCI, école autonome). Le catalogue complet des formes refusées est tenu
 * par le test unitaire de la règle.
 *
 * @see app/Rules/NotificationActionUrl.php
 * @see tests/Unit/Rules/NotificationActionUrlTest.php
 */
final class CreateNotificationActionUrlTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    /** @return iterable<string, array{0: bool}> */
    public static function volets(): iterable
    {
        yield 'école reliée à KLASSCI' => [false];
        yield 'école autonome' => [true];
    }

    /** @return iterable<string, array{0: bool, 1: string}> */
    public static function refusees(): iterable
    {
        foreach (self::volets() as $volet => [$autonome]) {
            foreach (['javascript:alert(1)', 'data://text/html,x', 'file:///etc/passwd', 'http://exemple.ci/x', '//exemple.ci/x', '/%2F%2Fexemple.ci'] as $url) {
                yield "$volet, $url" => [$autonome, $url];
            }
        }
    }

    #[DataProvider('refusees')]
    public function test_un_lien_hors_regle_est_refuse_sans_rien_ecrire(bool $autonome, string $url): void
    {
        [$coordinateur, $destinataire] = $this->ecole($autonome);

        $this->asTenant($coordinateur)
            ->postJson('/api/admin/notifications/create', $this->payload($destinataire, $url))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['action_url']);

        self::assertSame(0, Notification::withoutGlobalScopes()->count(), 'une notification a ete ecrite malgre le refus');
    }

    /** @return iterable<string, array{0: bool, 1: ?string}> */
    public static function acceptees(): iterable
    {
        foreach (self::volets() as $volet => [$autonome]) {
            foreach (['/student/evaluations/3', 'https://exemple.ci/x?y=1', null] as $url) {
                yield "$volet, " . ($url ?? 'aucun lien') => [$autonome, $url];
            }
        }
    }

    #[DataProvider('acceptees')]
    public function test_un_chemin_interne_ou_https_est_enregistre(bool $autonome, ?string $url): void
    {
        [$coordinateur, $destinataire] = $this->ecole($autonome);

        $this->asTenant($coordinateur)
            ->postJson('/api/admin/notifications/create', $this->payload($destinataire, $url))
            ->assertOk();

        $notification = Notification::withoutGlobalScopes()->sole();
        self::assertSame($destinataire->id, $notification->user_id);
        self::assertSame($url, $notification->data['action_url'] ?? null);
    }

    /**
     * Les normaliseurs de l'application passent AVANT la règle : `TrimStrings`
     * retire les blancs de tête et de fin, et `ConvertEmptyStringsToNull` fait
     * d'une chaîne vide « aucun lien ». La règle juge donc la valeur normalisée.
     *
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function normalisees(): iterable
    {
        yield 'blancs autour d un chemin interne' => ['  /student/evaluations/3  ', '/student/evaluations/3'];
        yield 'chaîne vide' => ['', null];
    }

    #[DataProvider('normalisees')]
    public function test_la_regle_juge_la_valeur_normalisee(string $saisie, ?string $enregistree): void
    {
        [$coordinateur, $destinataire] = $this->ecole(false);

        $this->asTenant($coordinateur)
            ->postJson('/api/admin/notifications/create', $this->payload($destinataire, $saisie))
            ->assertOk();

        self::assertSame($enregistree, Notification::withoutGlobalScopes()->sole()->data['action_url'] ?? null);
    }

    /** @return array{0: User, 1: User} */
    private function ecole(bool $autonome): array
    {
        $ecole = $autonome ? Institution::factory()->standalone()->create() : Institution::factory()->create();

        return [
            User::factory()->create(['institution_id' => $ecole->id, 'role' => 'coordinateur']),
            User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant']),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(User $destinataire, ?string $url): array
    {
        return [
            'user_id' => $destinataire->id,
            'title' => 'Rappel',
            'message' => 'Le devoir est en ligne.',
            'type' => 'info',
            'action_url' => $url,
        ];
    }
}
