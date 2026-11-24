<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #871 — le jeton Jitsi expire à la fin d'accès de SA visio, et non après une durée
 * fixe de 2 h qui coupait la seconde moitié d'un cours.
 *
 * La fin d'accès est celle qui refuse « rejoindre » (`FenetreVisio::finDAcces`) : la
 * fin de la séance, prolongée jusqu'à `démarrée + 4 h`. Fin inconnue (KLASSCI) : 4 h
 * depuis l'entrée. Un plafond de SÛRETÉ (12 h depuis l'entrée) borne une fin erronée.
 *
 * Les deux volets : école autonome (fin estimée, début + 2 h) et école KLASSCI (fin
 * réelle `fin_seance`, ou inconnue). Vrai jeton, horloge figée loin du jour réel :
 * l'ancienne règle (`time() + 7200`) ne peut tomber juste par hasard.
 */
final class JetonVisioCouvreLaFenetreTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const SECRET = 'secret-de-test-jamais-utilise-en-production';

    private const KLASSCI_CLASSE = 55;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Config::set('services.visio.jitsi.app_secret', self::SECRET);
        Config::set('services.visio.jitsi.domain', 'visio.klassci.test');
    }

    /** T1 — école autonome : un cours de 09:00 dont la visio démarre à 09:05 garde son jeton jusqu'à 13:05. */
    public function test_ecole_autonome_le_jeton_couvre_les_4_h_de_la_visio(): void
    {
        [$eleve, $seance] = $this->seanceAutonome();

        $this->travelTo($this->instant('09:10:00'));

        self::assertSame($this->instant('13:05:00')->getTimestamp(), $this->expiration($eleve, $seance));
    }

    /** T2 — école KLASSCI : un cours long, fini à 15:00, garde son jeton jusqu'à sa fin réelle. */
    public function test_ecole_klassci_le_jeton_couvre_la_fin_reelle_d_un_cours_long(): void
    {
        [$eleve, $seance] = $this->seanceKlassci('2026-10-05 15:00:00');

        $this->travelTo($this->instant('09:10:00'));

        self::assertSame($this->instant('15:00:00')->getTimestamp(), $this->expiration($eleve, $seance));
    }

    /** T3 — école KLASSCI sans fin connue : la fenêtre ne refuse jamais, le jeton vaut 4 h depuis l'entrée. */
    public function test_ecole_klassci_sans_fin_connue_le_jeton_vaut_4_h_depuis_l_entree(): void
    {
        [$eleve, $seance] = $this->seanceKlassci(null);

        $this->travelTo($this->instant('10:00:00'));

        self::assertSame($this->instant('14:00:00')->getTimestamp(), $this->expiration($eleve, $seance));
    }

    /** T4 — une entrée tardive n'obtient pas un jeton qui déborde la fenêtre. */
    public function test_une_entree_tardive_ne_deborde_pas_la_fenetre(): void
    {
        [$eleve, $seance] = $this->seanceAutonome();

        $this->travelTo($this->instant('12:30:00'));

        self::assertSame($this->instant('13:05:00')->getTimestamp(), $this->expiration($eleve, $seance));
    }

    /** T5 — une seule source : le jeton meurt à l'instant même où rejoindre devient refusé. */
    public function test_le_jeton_expire_quand_rejoindre_devient_refuse(): void
    {
        [$eleve, $seance] = $this->seanceAutonome();
        $this->travelTo($this->instant('10:00:00'));
        $exp = $this->expiration($eleve, $seance);

        $this->travelTo(Carbon::createFromTimestamp($exp - 1, 'UTC'));
        $this->asTenant($eleve)->postJson("/api/lms/seances/{$seance->id}/join")->assertOk();

        $this->travelTo(Carbon::createFromTimestamp($exp + 1, 'UTC'));
        $this->asTenant($eleve)->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertStatus(409)->assertJsonPath('reason', 'seance_past');
    }

    /** T6 — sûreté : une fin aberrante venue de KLASSCI (+72 h) ne signe pas un jeton de trois jours. */
    public function test_une_fin_aberrante_est_plafonnee_a_12_h_depuis_l_entree(): void
    {
        [$eleve, $seance] = $this->seanceKlassci('2026-10-08 09:00:00');

        $this->travelTo($this->instant('09:10:00'));

        self::assertSame($this->instant('21:10:00')->getTimestamp(), $this->expiration($eleve, $seance));
    }

    /** T7 — à l'instant limite, le jeton vit encore une minute : jamais signé déjà mort. */
    public function test_a_l_instant_limite_le_jeton_vit_encore_une_minute(): void
    {
        [$eleve, $seance] = $this->seanceAutonome();

        $this->travelTo($this->instant('13:05:00'));

        self::assertSame($this->instant('13:06:00')->getTimestamp(), $this->expiration($eleve, $seance));
    }

    private function expiration(User $eleve, Seance $seance): int
    {
        $jeton = $this->asTenant($eleve)->postJson("/api/lms/seances/{$seance->id}/join")
            ->assertOk()->json('data.visio_token');
        self::assertIsString($jeton);

        $parts = explode('.', $jeton);
        self::assertCount(3, $parts);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        self::assertIsArray($claims);
        self::assertIsInt($claims['exp'] ?? null);

        return $claims['exp'];
    }

    /** @return array{0: User, 1: Seance} */
    private function seanceAutonome(): array
    {
        $ecole = Institution::factory()->standalone()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);

        return [$this->eleveDe($ecole, $classe), $this->seance($ecole, [
            'classe_id' => $classe->id, 'klassci_seance_id' => null, 'klassci_classe_id' => null, 'fin_seance' => null,
        ])];
    }

    /** @return array{0: User, 1: Seance} */
    private function seanceKlassci(?string $fin): array
    {
        $ecole = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => self::KLASSCI_CLASSE]);

        return [$this->eleveDe($ecole, $classe), $this->seance($ecole, [
            'klassci_seance_id' => 871, 'klassci_classe_id' => self::KLASSCI_CLASSE, 'fin_seance' => $fin,
        ])];
    }

    /** @param  array<string, mixed>  $attributs */
    private function seance(Institution $ecole, array $attributs): Seance
    {
        return Seance::factory()->withVisio()->for($ecole)->create($attributs + [
            'date_seance' => '2026-10-05 09:00:00', 'is_active' => true,
            'visio_status' => 'active', 'visio_active' => true, 'visio_started_at' => '2026-10-05 09:05:00',
        ]);
    }

    private function eleveDe(Institution $ecole, Classe $classe): User
    {
        $eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant', 'klassci_token' => null]);
        DB::table('classe_etudiant')->insert(['classe_id' => $classe->id, 'user_id' => $eleve->id, 'institution_id' => $classe->institution_id, 'statut' => 'actif',
            'date_inscription' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()]);

        return $eleve;
    }

    private function instant(string $heure): Carbon
    {
        return Carbon::parse("2026-10-05 {$heure}", 'UTC');
    }
}
