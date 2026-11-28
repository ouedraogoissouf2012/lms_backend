<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Models\ActivationToken;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #808 — « mot de passe oublié » ne promet plus un courriel qui ne part jamais.
 *
 * Aucun envoi n'existe : la réponse affirmait pourtant « un courriel a été envoyé », et un
 * jeton était créé sans être jamais délivré. Désormais :
 * - la réponse dit ce qui est vrai, par un motif (le texte vit dans le catalogue du front ;
 *   `message` en est le repli) : le mot de passe est celui de KLASSCI en école KLASSCI, la
 *   réinitialisation en ligne n'est pas encore disponible en école autonome ;
 * - elle est IDENTIQUE pour un compte connu ou inconnu (aucune énumération) ;
 * - aucun jeton n'est créé.
 */
final class MotDePasseOublieTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{0: InstitutionMode, 1: string, 2: string}> */
    public static function volets(): iterable
    {
        yield 'école autonome' => [
            InstitutionMode::Standalone,
            'reinitialisation_indisponible',
            'La réinitialisation du mot de passe en ligne n\'est pas encore disponible.',
        ];
        yield 'école KLASSCI' => [
            InstitutionMode::Klassci,
            'mot_de_passe_klassci',
            'Votre mot de passe est celui de KLASSCI : réinitialisez-le depuis KLASSCI.',
        ];
    }

    #[DataProvider('volets')]
    public function test_la_reponse_dit_ce_qui_est_vrai(InstitutionMode $mode, string $motif, string $message): void
    {
        $ecole = $this->ecole($mode);
        $this->compte($ecole, 'connu@ecole.test');

        $this->demander($ecole, 'connu@ecole.test')->assertOk()
            ->assertExactJson(['success' => true, 'reason' => $motif, 'message' => $message]);
    }

    #[DataProvider('volets')]
    public function test_aucun_envoi_n_est_affirme(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $this->compte($ecole, 'connu@ecole.test');

        $corps = (string) $this->demander($ecole, 'connu@ecole.test')->getContent();

        self::assertStringNotContainsStringIgnoringCase('envoy', $corps);
        self::assertStringNotContainsStringIgnoringCase('courriel', $corps);
    }

    #[DataProvider('volets')]
    public function test_un_compte_connu_et_un_inconnu_recoivent_la_meme_reponse(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $this->compte($ecole, 'connu@ecole.test');

        $connu = $this->demander($ecole, 'connu@ecole.test');
        $inconnu = $this->demander($ecole, 'inconnu@ecole.test');

        self::assertSame($inconnu->getStatusCode(), $connu->getStatusCode());
        self::assertSame((string) $inconnu->getContent(), (string) $connu->getContent());
    }

    #[DataProvider('volets')]
    public function test_aucun_jeton_n_est_cree(InstitutionMode $mode): void
    {
        $ecole = $this->ecole($mode);
        $this->compte($ecole, 'connu@ecole.test');

        $this->demander($ecole, 'connu@ecole.test')->assertOk();

        // #1306 : la table du broker Laravel est retirée ; le seul jeton de réinitialisation est
        // celui d'activation (#808), et aucun n'est créé ici.
        self::assertSame(0, DB::table((new ActivationToken)->getTable())->count());
    }

    private function ecole(InstitutionMode $mode): Institution
    {
        return Institution::factory()->create(['mode' => $mode, 'slug' => 'ecole-'.$mode->value]);
    }

    private function compte(Institution $ecole, string $email): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'email' => $email]);
    }

    private function demander(Institution $ecole, string $email): TestResponse
    {
        return $this->withHeader('X-Institution', $ecole->slug)
            ->postJson('/api/auth/forgot-password', ['email' => $email]);
    }
}
