<?php

declare(strict_types=1);

namespace Tests\Feature\SchoolRegistration;

use App\Jobs\EnvoyerLeLienDActivation;
use App\Models\Institution;
use App\Models\SchoolRegistrationRequest;
use App\Models\User;
use App\Notifications\LienDActivationDuCompte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1620 — à la validation d'une école, le lien d'activation part au titulaire par courriel.
 *
 * UN jeton, UN lien : `ActivationTokenService::emettre()` invalide les jetons précédents du compte,
 * donc le lien envoyé est celui que le supradmin reçoit en secours, jamais un second. La tâche le
 * transporte CHIFFRÉ (`ShouldBeEncrypted`) : la file est en base, et un jeton en clair y dormirait.
 * Sans canal de courriel, rien n'est posé et la réponse le dit (`activation_envoyee_a` = null).
 *
 * Vrai jeton (`asTenant`) pour le supradmin.
 */
final class LeLienDActivationPartAuTitulaireTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_la_validation_pose_l_envoi_du_meme_lien_au_titulaire(): void
    {
        $this->brancherLeCanal();
        Queue::fake();

        $reponse = $this->valider($this->demande())->assertOk();

        $titulaire = User::query()->withoutGlobalScopes()->where('email', 'titulaire@ecole-1620.test')->firstOrFail();
        $reponse->assertJsonPath('data.activation_envoyee_a', 'titulaire@ecole-1620.test');
        Queue::assertPushed(EnvoyerLeLienDActivation::class, static fn (EnvoyerLeLienDActivation $envoi): bool => $envoi->titulaireId === $titulaire->id
            && $envoi->institutionId === $titulaire->institution_id
            && $envoi->lien === $reponse->json('data.activation_url'));
    }

    public function test_le_courriel_porte_le_lien_et_le_nom_de_l_ecole(): void
    {
        $this->brancherLeCanal();
        Queue::fake();
        Notification::fake();
        $lien = (string) $this->valider($this->demande())->json('data.activation_url');

        $this->executerLEnvoi();

        $titulaire = User::query()->withoutGlobalScopes()->where('email', 'titulaire@ecole-1620.test')->firstOrFail();
        Notification::assertSentTo($titulaire, LienDActivationDuCompte::class, static function (LienDActivationDuCompte $courriel) use ($titulaire, $lien): bool {
            $message = $courriel->toMail($titulaire);

            return $message->actionUrl === $lien && str_contains((string) json_encode($message->toArray()), 'Ecole Mille Six Cent Vingt');
        });
    }

    public function test_le_lien_recu_par_courriel_active_le_compte(): void
    {
        $this->brancherLeCanal();
        Queue::fake();
        $this->valider($this->demande())->assertOk();
        $envoi = Queue::pushed(EnvoyerLeLienDActivation::class)->first();
        self::assertInstanceOf(EnvoyerLeLienDActivation::class, $envoi);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/activation', [
            'token' => substr($envoi->lien, (int) strrpos($envoi->lien, '/') + 1),
            'password' => 'MotDePasseSolide#2026', 'password_confirmation' => 'MotDePasseSolide#2026',
        ])->assertOk();
    }

    public function test_la_tache_en_file_ne_porte_pas_le_jeton_en_clair(): void
    {
        $this->brancherLeCanal();
        config(['queue.default' => 'database']);

        $lien = (string) $this->valider($this->demande())->json('data.activation_url');
        $jeton = substr($lien, (int) strrpos($lien, '/') + 1);

        $charge = (string) DB::table('jobs')->where('payload', 'like', '%EnvoyerLeLienDActivation%')->value('payload');
        self::assertNotSame('', $charge, 'La tâche d\'envoi devait être en file.');
        self::assertStringNotContainsString($jeton, $charge);
    }

    public function test_sans_canal_de_courriel_rien_n_est_pose_et_la_reponse_le_dit(): void
    {
        config(['mail.default' => 'log']);
        Queue::fake();

        $this->valider($this->demande())->assertOk()
            ->assertJsonPath('data.activation_envoyee_a', null)
            ->assertJsonPath('data.activation_url', fn (mixed $url): bool => is_string($url) && $url !== '');

        Queue::assertNotPushed(EnvoyerLeLienDActivation::class);
    }

    public function test_une_validation_qui_echoue_n_envoie_rien(): void
    {
        $this->brancherLeCanal();
        Queue::fake();
        Institution::factory()->create(['slug' => 'ecole-1620']);

        // La collision frappe l'écriture, dans la transaction : la validation échoue.
        self::assertFalse($this->valider($this->demande())->isSuccessful());

        Queue::assertNotPushed(EnvoyerLeLienDActivation::class);
    }

    /** Brevo, tel que le user le pose dans Dokploy. */
    private function brancherLeCanal(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
            'mail.mailers.smtp.username' => 'compte@brevo.test',
            'mail.from.address' => 'ne-pas-repondre@lms.test',
            'activation.url_front' => 'https://lms.test',
        ]);
    }

    private function demande(): SchoolRegistrationRequest
    {
        return SchoolRegistrationRequest::factory()->create([
            'email_demandeur' => 'titulaire@ecole-1620.test',
            'nom_demandeur' => 'Awa Kouassi',
            'nom_ecole' => 'Ecole Mille Six Cent Vingt',
            'slug_souhaite' => 'ecole-1620',
        ]);
    }

    /** @return TestResponse<Response> */
    private function valider(SchoolRegistrationRequest $demande): TestResponse
    {
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin', 'klassci_token' => null]);

        return $this->asTenant($supradmin)->postJson("/api/admin/school-requests/{$demande->getKey()}/validate");
    }

    private function executerLEnvoi(): void
    {
        $envoi = Queue::pushed(EnvoyerLeLienDActivation::class)->first();
        self::assertInstanceOf(EnvoyerLeLienDActivation::class, $envoi);
        app()->call([$envoi, 'handle']);
    }
}
