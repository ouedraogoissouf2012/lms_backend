<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Jobs\EnvoyerLeLienDeReinitialisation;
use App\Models\ActivationToken;
use App\Models\Institution;
use App\Models\User;
use App\Notifications\LienDeReinitialisationDuMotDePasse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #808, grande partie — en école autonome, « mot de passe oublié » envoie réellement un lien,
 * par le courriel (Brevo, configuré par l'environnement).
 *
 * - Le lien est le jeton à usage unique de #803 (ADR-803-02), haché en base, valable 60
 *   minutes, et mène à la page `/activation/{jeton}` du front, qui existe déjà.
 * - Un job en file est posé pour CHAQUE demande : la requête fait le même travail pour un
 *   compte connu ou inconnu, et la réponse est la même.
 * - Anti-bombardement, par compte et dans le job : un seul lien par intervalle.
 * - Canal indisponible (la prod tant que Brevo n'est pas posé) : rien ne part, rien n'est
 *   affirmé.
 */
final class LienDeReinitialisationTest extends TestCase
{
    use RefreshDatabase;

    private const NOUVEAU = 'Nouveau-mot-808';

    private const LIEN_ENVOYE = [
        'success' => true,
        'reason' => 'lien_envoye',
        'message' => 'Si un compte correspond à cette adresse, un lien pour choisir un nouveau mot de passe va vous être envoyé.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_un_compte_connu_recoit_un_lien_qui_pose_son_mot_de_passe(): void
    {
        $this->brancherLeCanal();
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $compte = $this->compte($ecole, 'connu@ecole.test');

        $this->demander($ecole, 'connu@ecole.test')->assertOk()->assertExactJson(self::LIEN_ENVOYE);

        $lien = $this->lienEnvoyeA($compte);
        self::assertStringStartsWith('https://lms.test/activation/', $lien);
        $jeton = basename($lien);
        self::assertTrue(ActivationToken::query()->where('token_hash', hash('sha256', $jeton))->exists(), 'Le jeton est en base, haché.');
        self::assertFalse(ActivationToken::query()->where('token_hash', $jeton)->exists(), 'Jamais en clair.');

        $this->activer($jeton)->assertOk();
        self::assertTrue(Hash::check(self::NOUVEAU, (string) User::withoutGlobalScopes()->whereKey($compte->id)->value('password')));
    }

    public function test_le_lien_ne_sert_qu_une_fois(): void
    {
        $this->brancherLeCanal();
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $compte = $this->compte($ecole, 'connu@ecole.test');
        $this->demander($ecole, 'connu@ecole.test')->assertOk();
        $jeton = basename($this->lienEnvoyeA($compte));

        $this->activer($jeton)->assertOk();
        $this->activer($jeton)->assertStatus(410);
    }

    public function test_le_lien_expire_apres_soixante_minutes(): void
    {
        $this->brancherLeCanal();
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $compte = $this->compte($ecole, 'connu@ecole.test');
        $this->demander($ecole, 'connu@ecole.test')->assertOk();
        $jeton = basename($this->lienEnvoyeA($compte));

        $this->travel(61)->minutes();

        $this->activer($jeton)->assertStatus(410);
        self::assertFalse(Hash::check(self::NOUVEAU, (string) User::withoutGlobalScopes()->whereKey($compte->id)->value('password')));
    }

    public function test_un_compte_inconnu_recoit_la_meme_reponse_et_rien_n_est_cree(): void
    {
        $this->brancherLeCanal();
        $ecole = $this->ecole(InstitutionMode::Standalone);

        $this->demander($ecole, 'inconnu@ecole.test')->assertOk()->assertExactJson(self::LIEN_ENVOYE);

        Notification::assertNothingSent();
        self::assertSame(0, ActivationToken::query()->count());
    }

    /** Anti-bombardement : par compte, dans le job ; la réponse ne change jamais. */
    public function test_deux_demandes_rapprochees_n_envoient_qu_un_lien(): void
    {
        $this->brancherLeCanal();
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $compte = $this->compte($ecole, 'connu@ecole.test');

        $this->demander($ecole, 'connu@ecole.test')->assertOk()->assertExactJson(self::LIEN_ENVOYE);
        $this->demander($ecole, 'connu@ecole.test')->assertOk()->assertExactJson(self::LIEN_ENVOYE);

        Notification::assertSentToTimes($compte, LienDeReinitialisationDuMotDePasse::class, 1);
        self::assertSame(1, ActivationToken::query()->count());
    }

    public function test_deux_demandes_rapprochees_pour_un_inconnu_ne_creent_rien(): void
    {
        $this->brancherLeCanal();
        $ecole = $this->ecole(InstitutionMode::Standalone);

        $this->demander($ecole, 'inconnu@ecole.test')->assertOk()->assertExactJson(self::LIEN_ENVOYE);
        $this->demander($ecole, 'inconnu@ecole.test')->assertOk()->assertExactJson(self::LIEN_ENVOYE);

        Notification::assertNothingSent();
        self::assertSame(0, ActivationToken::query()->count());
    }

    /** Après l'intervalle, un nouveau lien part, et le précédent cesse de valoir. */
    public function test_apres_l_intervalle_un_nouveau_lien_remplace_le_precedent(): void
    {
        $this->brancherLeCanal();
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $compte = $this->compte($ecole, 'connu@ecole.test');
        $this->demander($ecole, 'connu@ecole.test')->assertOk();
        $premier = basename($this->lienEnvoyeA($compte));

        $this->travel(3)->minutes();
        $this->demander($ecole, 'connu@ecole.test')->assertOk();

        Notification::assertSentToTimes($compte, LienDeReinitialisationDuMotDePasse::class, 2);
        $this->activer($premier)->assertStatus(410);
    }

    /** Chaque demande pose un job : le travail de la requête ne dit rien du compte. */
    public function test_chaque_demande_met_un_job_en_file_compte_connu_ou_inconnu(): void
    {
        $this->brancherLeCanal();
        Queue::fake();
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $this->compte($ecole, 'connu@ecole.test');

        $this->demander($ecole, 'connu@ecole.test')->assertOk();
        $this->demander($ecole, 'inconnu@ecole.test')->assertOk();

        Queue::assertPushed(EnvoyerLeLienDeReinitialisation::class, 2);
    }

    /** La prod tant que Brevo et ACTIVATION_URL_FRONT ne sont pas posés : rien ne part. */
    public function test_canal_indisponible_ni_job_ni_jeton_ni_courriel(): void
    {
        Queue::fake();
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $this->compte($ecole, 'connu@ecole.test');

        $this->demander($ecole, 'connu@ecole.test')->assertOk()->assertJsonPath('reason', 'reinitialisation_indisponible');

        Queue::assertNothingPushed();
        self::assertSame(0, ActivationToken::query()->count());
        Notification::assertNothingSent();
    }

    public function test_en_ecole_klassci_rien_ne_part(): void
    {
        $this->brancherLeCanal();
        Queue::fake();
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $this->compte($ecole, 'connu@ecole.test');

        $this->demander($ecole, 'connu@ecole.test')->assertOk()->assertJsonPath('reason', 'mot_de_passe_klassci');

        Queue::assertNothingPushed();
        self::assertSame(0, ActivationToken::query()->count());
    }

    /** Brevo, tel que le user le posera dans Dokploy ; la file en synchrone pour le test. */
    private function brancherLeCanal(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.brevo.com',
            'mail.mailers.smtp.username' => 'compte@brevo.test',
            'mail.from.address' => 'ne-pas-repondre@lms.test',
            'activation.url_front' => 'https://lms.test',
            'queue.default' => 'sync',
        ]);
    }

    private function ecole(InstitutionMode $mode): Institution
    {
        return Institution::factory()->create(['mode' => $mode, 'slug' => 'ecole-'.$mode->value]);
    }

    private function compte(Institution $ecole, string $email): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'email' => $email, 'role' => 'enseignant']);
    }

    private function demander(Institution $ecole, string $email): TestResponse
    {
        return $this->withHeader('X-Institution', $ecole->slug)
            ->postJson('/api/auth/forgot-password', ['email' => $email]);
    }

    private function activer(string $jeton): TestResponse
    {
        return $this->postJson('/api/activation', [
            'token' => $jeton,
            'password' => self::NOUVEAU,
            'password_confirmation' => self::NOUVEAU,
        ]);
    }

    private function lienEnvoyeA(User $compte): string
    {
        $lien = null;
        Notification::assertSentTo($compte, LienDeReinitialisationDuMotDePasse::class, function (LienDeReinitialisationDuMotDePasse $courriel) use ($compte, &$lien): bool {
            $lien = $courriel->toMail($compte)->actionUrl;

            return true;
        });

        return (string) $lien;
    }
}
