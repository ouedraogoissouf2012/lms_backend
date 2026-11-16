<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Models\Institution;
use App\Services\Enrollment\RejoindreParCodeService;
use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\PreparesClasseWithCode;
use Tests\TestCase;

/**
 * Les plafonds de la porte anonyme ne comptent que les ÉCHECS (#915).
 *
 * Une inscription réussie n'est jamais un essai de trop : une classe entière
 * derrière le wifi de l'école (une seule IP, NAT) s'inscrit le même matin. Les
 * codes faux sont bornés par minute et par jour pour une adresse, et par jour
 * pour une école ; une adresse ou une école au plafond n'éteint plus les autres.
 *
 * @see docs/adr/2026-11-16-915-01-plafonds-de-la-porte-anonyme.md
 */
final class PlafondsDeLaPorteAnonymeTest extends TestCase
{
    use PreparesClasseWithCode;
    use RefreshDatabase;

    private const URL = '/api/inscriptions';

    /** Le scénario de la rentrée : 40 élèves derrière une seule IP, en deux minutes. */
    public function test_quarante_eleves_derriere_une_ip_s_inscrivent_en_deux_minutes(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();

        for ($i = 1; $i <= 40; $i++) {
            $this->postJson(self::URL, $this->charge($code, "eleve{$i}@test.ci"), $this->entete($ecole))
                ->assertSuccessful();
            $this->travel(3)->seconds();
        }

        self::assertSame(40, DB::table('classe_etudiant')->where('classe_id', $classe->getKey())->count());
        self::assertSame(0, RateLimiter::attempts(RejoindreParCodeService::cleDesEchecs((int) $ecole->getKey())));
    }

    /** Une adresse qui essaie des codes faux est arrêtée au sixième échec de la minute. */
    public function test_apres_des_inscriptions_reussies_le_sixieme_code_faux_de_la_minute_est_refuse(): void
    {
        [$ecole, $code] = $this->classeAvecCode();

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson(self::URL, $this->charge($code, "eleve{$i}@test.ci"), $this->entete($ecole))->assertSuccessful();
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(self::URL, $this->charge('ZZZZZZ', "faux{$i}@test.ci"), $this->entete($ecole))->assertNotFound();
        }

        $this->postJson(self::URL, $this->charge('ZZZZZZ', 'faux6@test.ci'), $this->entete($ecole))
            ->assertStatus(429)
            ->assertJsonPath('reason', 'ip_quota_exceeded');
    }

    /** Trente codes faux dans la journée ferment l'adresse, et elle seule. */
    public function test_le_plafond_du_jour_d_une_adresse_n_eteint_pas_une_autre_adresse(): void
    {
        if (app('cache')->store()->getStore() instanceof RedisStore) {
            self::markTestSkipped('TTL redis suit l horloge réelle, pas le time-travel Carbon.');
        }
        [$ecole] = $this->classeAvecCode();

        for ($i = 1; $i <= 30; $i++) {
            $this->postJson(self::URL, $this->charge('ZZZZZZ', "faux{$i}@test.ci"), $this->entete($ecole))->assertNotFound();
            // Sous la borne de la minute : cinq échecs, puis une minute passe.
            if ($i % 5 === 0) {
                $this->travel(61)->seconds();
            }
        }

        $this->postJson(self::URL, $this->charge('ZZZZZZ', 'faux31@test.ci'), $this->entete($ecole))
            ->assertStatus(429)
            ->assertJsonPath('reason', 'ip_daily_cap_reached');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson(self::URL, $this->charge('ZZZZZZ', 'autre@test.ci'), $this->entete($ecole))
            ->assertNotFound();
    }

    /** Une école au plafond d'échecs n'éteint pas une autre école. */
    public function test_le_plafond_d_echecs_d_une_ecole_n_eteint_pas_une_autre_ecole(): void
    {
        [$visee, $codeVise] = $this->classeAvecCode();
        [$autre, $codeAutre] = $this->classeAvecCode();

        for ($i = 0; $i < 200; $i++) {
            RateLimiter::hit(RejoindreParCodeService::cleDesEchecs((int) $visee->getKey()), 86_400);
        }

        $this->postJson(self::URL, $this->charge($codeVise, 'awa@test.ci'), $this->entete($visee))
            ->assertStatus(429)
            ->assertJsonPath('reason', 'institution_cap_reached');
        $this->postJson(self::URL, $this->charge($codeAutre, 'awa@autre.ci'), $this->entete($autre))
            ->assertSuccessful();
    }

    // ───────────────────────── fixtures

    /** @return array<string, string> */
    private function entete(Institution $ecole): array
    {
        return ['X-Institution' => (string) $ecole->slug];
    }

    /** @return array<string, string> */
    private function charge(string $code, string $email): array
    {
        return [
            'code' => $code,
            'nom' => 'Eleve',
            'email' => $email,
            'password' => 'mot-de-passe-choisi',
            'password_confirmation' => 'mot-de-passe-choisi',
        ];
    }
}
