<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Enums\InstitutionMode;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Psr\Log\LoggerInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Support\Log\JournalRelu;
use Tests\TestCase;

/**
 * #1213 — le jeton API KLASSCI d'une école s'efface quand elle se détache.
 *
 * Décision du user (22/11) : le jeton est EFFACÉ, avec une trace d'audit, quand l'URL est
 * vidée, quand elle change sans qu'un nouveau jeton soit saisi, ou quand l'école bascule en
 * autonome. Un jeton gardé après un détachement partirait, au réattachement, vers une cible
 * qui n'est peut-être plus la sienne.
 *
 * L'audit dit POURQUOI le jeton est parti ; il ne contient jamais sa valeur.
 *
 * Compte supradmin plateforme, vrai jeton.
 */
final class LeJetonSEffaceAuDetachementTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const URL = 'https://ecole.klassci.test/api/lms';

    private const AUTRE_URL = 'https://autre.klassci.test/api/lms';

    private const JETON = 'jeton-de-l-ecole-attachee';

    private const NOUVEAU = 'jeton-de-la-nouvelle-cible';

    private JournalRelu $journal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->journal = new JournalRelu;
        $this->app->instance(LoggerInterface::class, $this->journal);
    }

    // ── Ce qui efface ──

    public function test_vider_l_url_efface_le_jeton_et_laisse_une_trace(): void
    {
        $ecole = $this->ecoleAttachee();

        $this->modifier($ecole, ['klassci_api_url' => null])->assertOk();

        $this->assertEfface($ecole, 'url_videe');
    }

    /** Sans URL, aucune cible : un jeton saisi dans la même requête ne survit pas non plus. */
    public function test_vider_l_url_efface_meme_un_jeton_saisi_dans_la_meme_requete(): void
    {
        $ecole = $this->ecoleAttachee();

        $this->modifier($ecole, ['klassci_api_url' => null, 'klassci_api_token' => self::NOUVEAU])->assertOk();

        $this->assertEfface($ecole, 'url_videe');
    }

    public function test_changer_l_url_sans_nouveau_jeton_efface_l_ancien(): void
    {
        $ecole = $this->ecoleAttachee();

        $this->modifier($ecole, ['klassci_api_url' => self::AUTRE_URL])->assertOk();

        $this->assertEfface($ecole, 'url_changee');
    }

    public function test_basculer_en_autonome_efface_le_jeton(): void
    {
        $ecole = $this->ecoleAttachee();

        $this->basculer($ecole, InstitutionMode::Standalone)->assertOk();

        $this->assertEfface($ecole, 'bascule_autonome');
    }

    // ── Ce qui garde ──

    /** Le nouveau jeton est celui de la nouvelle cible : rien n'est effacé. */
    public function test_changer_l_url_avec_un_nouveau_jeton_garde_le_nouveau(): void
    {
        $ecole = $this->ecoleAttachee();

        $this->modifier($ecole, ['klassci_api_url' => self::AUTRE_URL, 'klassci_api_token' => self::NOUVEAU])->assertOk();

        self::assertSame(self::NOUVEAU, $ecole->fresh()?->klassci_api_token);
        self::assertSame(0, $this->effacements($ecole));
    }

    public function test_une_modification_qui_ne_touche_pas_l_url_garde_le_jeton(): void
    {
        $ecole = $this->ecoleAttachee();

        $this->modifier($ecole, ['name' => 'Nouveau nom', 'klassci_api_url' => self::URL])->assertOk();

        self::assertSame(self::JETON, $ecole->fresh()?->klassci_api_token);
        self::assertSame(0, $this->effacements($ecole));
    }

    /**
     * La même URL saisie avec un « / » final n'est pas une autre cible : le mutateur de
     * l'URL la rend canonique (#1331), elle n'est donc pas « changée » et le jeton reste.
     */
    public function test_la_meme_url_saisie_avec_un_slash_final_garde_le_jeton(): void
    {
        $ecole = $this->ecoleAttachee();

        $this->modifier($ecole, ['klassci_api_url' => self::URL.'/'])->assertOk();

        self::assertSame(self::URL, $ecole->fresh()?->klassci_api_url);
        self::assertSame(self::JETON, $ecole->fresh()?->klassci_api_token);
        self::assertSame(0, $this->effacements($ecole));
    }

    public function test_basculer_vers_klassci_garde_le_jeton(): void
    {
        $ecole = Institution::factory()->create([
            'mode' => InstitutionMode::Standalone, 'klassci_api_url' => self::URL, 'klassci_api_token' => self::JETON,
        ]);

        $this->basculer($ecole, InstitutionMode::Klassci)->assertOk();

        self::assertSame(self::JETON, $ecole->fresh()?->klassci_api_token);
        self::assertSame(0, $this->effacements($ecole));
    }

    /** Rien à effacer, rien à tracer : l'audit ne dit que ce qui a réellement eu lieu. */
    public function test_une_ecole_sans_jeton_qui_se_detache_ne_laisse_aucune_trace_d_effacement(): void
    {
        $ecole = Institution::factory()->create(['klassci_api_url' => self::URL, 'klassci_api_token' => null]);

        $this->modifier($ecole, ['klassci_api_url' => null])->assertOk();
        $this->basculer($ecole, InstitutionMode::Standalone)->assertOk();

        self::assertSame(0, $this->effacements($ecole));
    }

    private function assertEfface(Institution $ecole, string $raison): void
    {
        self::assertNull($ecole->fresh()?->klassci_api_token, 'Le jeton a survécu au détachement.');

        $traces = AuditLog::query()->withoutGlobalScope('institution')
            ->where('action', 'institution.klassci_token_erased')->where('auditable_id', $ecole->id)->get();
        self::assertCount(1, $traces);
        self::assertSame(['raison' => $raison], $traces->sole()->after);

        foreach ([self::JETON, self::NOUVEAU] as $valeur) {
            self::assertStringNotContainsString($valeur, (string) json_encode($traces->sole()->toArray()));
            foreach ($this->journal->lignes as $ligne) {
                self::assertStringNotContainsString($valeur, $ligne);
            }
        }
    }

    private function effacements(Institution $ecole): int
    {
        return AuditLog::query()->withoutGlobalScope('institution')
            ->where('action', 'institution.klassci_token_erased')->where('auditable_id', $ecole->id)->count();
    }

    private function ecoleAttachee(): Institution
    {
        return Institution::factory()->create([
            'mode' => InstitutionMode::Klassci, 'klassci_api_url' => self::URL, 'klassci_api_token' => self::JETON,
        ]);
    }

    /** @param  array<string, mixed>  $charge */
    private function modifier(Institution $ecole, array $charge): TestResponse
    {
        return $this->asTenant($this->supradmin())->putJson("/api/admin/institutions/{$ecole->id}", $charge);
    }

    private function basculer(Institution $ecole, InstitutionMode $mode): TestResponse
    {
        return $this->asTenant($this->supradmin())->patchJson("/api/admin/institutions/{$ecole->id}/mode", ['mode' => $mode->value]);
    }

    private function supradmin(): User
    {
        return User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);
    }
}
