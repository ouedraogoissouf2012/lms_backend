<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1306, volet serveur : le mécanisme de réinitialisation de Laravel est retiré.
 *
 * Depuis #808, le lien de réinitialisation d'une école autonome est le jeton à usage unique de
 * #803 (ADR-803-02) : il part par courriel et se consomme sur `POST /api/activation`. Le broker
 * Laravel n'a jamais pu délivrer de jeton (le « mot de passe oublié » n'en crée plus depuis
 * #1277) : c'était une route publique qui acceptait un jeton, morte, et un piège de lecture.
 * Le front a cessé de l'appeler (frontend_lms#624).
 */
final class LeBrokerDeReinitialisationEstRetireTest extends TestCase
{
    use RefreshDatabase;

    /** Les classes du mécanisme retiré. */
    private const CLASSES_RETIREES = [
        'App\\Auth\\TenantAwarePasswordBrokerManager',
        'App\\Auth\\TenantAwareTokenRepository',
        'App\\Services\\Auth\\PasswordResetService',
        'App\\Http\\Requests\\ResetPasswordRequest',
    ];

    /** @return iterable<string, array{0: InstitutionMode}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [InstitutionMode::Standalone];
        yield 'école KLASSCI' => [InstitutionMode::Klassci];
    }

    #[DataProvider('volets')]
    public function test_la_route_de_reinitialisation_laravel_n_existe_plus(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        User::factory()->create(['institution_id' => $ecole->id, 'email' => 'connu@ecole.test']);

        $this->withHeader('X-Institution', $ecole->slug)
            ->postJson('/api/auth/reset-password', [
                'email' => 'connu@ecole.test',
                'token' => 'un-jeton-quelconque',
                'password' => 'MotDePasseSolide#2026',
                'password_confirmation' => 'MotDePasseSolide#2026',
            ])
            ->assertNotFound();
    }

    public function test_la_table_des_jetons_laravel_n_existe_plus(): void
    {
        self::assertFalse(Schema::hasTable('password_reset_tokens'));
    }

    /**
     * Les classes du mécanisme. La configuration du broker, elle, n'est pas vérifiée ici : Laravel
     * complète `config/auth.php` par ses propres défauts (`passwords` compris), son retrait du
     * fichier ne la rend donc pas nulle.
     */
    public function test_aucune_classe_du_mecanisme_ne_reste(): void
    {
        foreach (self::CLASSES_RETIREES as $classe) {
            self::assertFalse(class_exists($classe), "{$classe} est retirée.");
        }
    }

    /**
     * Le 410 d'un jeton mort sert aussi le lien de réinitialisation (#808) : il ne renvoie plus
     * seulement vers l'administrateur (commentaire de #1306). Un seul corps pour les trois jetons
     * morts (#839) : c'est le verrou de `ValidateSchoolRequestTest`.
     */
    public function test_un_jeton_mort_dit_aussi_qu_on_peut_en_redemander_un(): void
    {
        $this->postJson('/api/activation', [
            'token' => 'aucun-jeton-de-ce-nom',
            'password' => 'MotDePasseSolide#2026',
            'password_confirmation' => 'MotDePasseSolide#2026',
        ])
            ->assertStatus(410)
            ->assertJsonPath('message', 'Ce lien n\'est pas valide ou a expiré. Demandez-en un nouveau à votre administrateur, ou depuis « Mot de passe oublié ».');
    }

    /** Verrou : le « mot de passe oublié » vivant (#808, #1277) reste servi. */
    #[DataProvider('volets')]
    public function test_le_mot_de_passe_oublie_reste_servi(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);

        $this->withHeader('X-Institution', $ecole->slug)
            ->postJson('/api/auth/forgot-password', ['email' => 'connu@ecole.test'])
            ->assertOk();
    }

    private function ecole(InstitutionMode $mode): Institution
    {
        return Institution::factory()->create(['mode' => $mode, 'slug' => 'ecole-'.$mode->value]);
    }
}
