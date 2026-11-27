<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Report\AttendanceReportContextBuilder;
use App\Services\TenantManager;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1709 — le rapport de présences d'UNE classe ne rend que les séances de cette classe,
 * dans les deux volets.
 *
 * Une classe a deux identités, chacune dans son espace : son id local, que porte
 * `seances.classe_id` pour une séance créée dans le LMS (#846), et son `klassci_id`, que
 * porte `seances.klassci_classe_id` pour une séance miroir de KLASSCI. Le filtre lit
 * chacune dans son espace ; jamais l'une contre l'autre.
 *
 * Le jeu est connu : chaque scénario a une séance HORS de la classe demandée, dont les
 * présences ne doivent jamais apparaître. Un rapport qui couvrirait toute l'école les
 * compterait.
 */
final class RapportPresenceFiltreClasseTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const JOUR = '2026-11-20';

    private const RAPPORT = '/api/admin/reports/attendance';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::JOUR.' 12:00:00');
        $this->disableKlassciMiddleware();
    }

    public function test_ecole_autonome_le_rapport_ne_rend_que_les_seances_locales_de_la_classe(): void
    {
        $ecole = $this->ecole();
        $demandee = $this->classe($ecole, 'Terminale A');
        $autre = $this->classe($ecole, 'Terminale B');
        $this->presences($this->seanceLocale($ecole, $demandee), $ecole, 2);
        $this->presences($this->seanceLocale($ecole, $autre), $ecole, 1);
        $this->tenant($ecole);

        $this->assertSame(2, $this->total($demandee, $ecole));
        $this->assertSame(3, $this->totalSansFiltre($ecole), 'Sans filtre, le rapport couvre bien les deux classes.');
    }

    public function test_ecole_klassci_le_rapport_ne_rend_que_les_seances_miroirs_de_la_classe(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $demandee = $this->classeMiroir($ecole, 'L1 Info', 7701);
        $this->classeMiroir($ecole, 'L2 Info', 7702);
        $this->presences($this->seanceMiroir($ecole, 7701), $ecole, 2);
        $this->presences($this->seanceMiroir($ecole, 7702), $ecole, 1);
        $this->tenant($ecole);

        $this->assertSame(2, $this->total($demandee, $ecole));
    }

    public function test_une_classe_rend_ses_seances_par_ses_deux_identites(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $demandee = $this->classeMiroir($ecole, 'L1 Info', 7701);
        $this->presences($this->seanceMiroir($ecole, 7701), $ecole, 2);
        $this->presences($this->seanceLocale($ecole, $demandee), $ecole, 1);
        $this->presences($this->seanceMiroir($ecole, 7702), $ecole, 4);
        $this->tenant($ecole);

        $this->assertSame(3, $this->total($demandee, $ecole));
    }

    /**
     * Le filtre est explicite, pas seulement porté par le scope : sans école résolue, une
     * autre école qui partage le même `klassci_classe_id` (deux serveurs KLASSCI distincts)
     * ne doit pas entrer dans le rapport.
     */
    public function test_sans_ecole_resolue_le_filtre_reste_borne_a_l_ecole_de_l_acteur(): void
    {
        $sienne = $this->ecole(InstitutionMode::Klassci);
        $voisine = $this->ecole(InstitutionMode::Klassci);
        $demandee = $this->classeMiroir($sienne, 'L1 Info', 7701);
        $this->classeMiroir($voisine, 'L1 Info', 7701);
        $this->presences($this->seanceMiroir($sienne, 7701), $sienne, 2);
        $this->presences($this->seanceMiroir($voisine, 7701), $voisine, 5);
        app(TenantManager::class)->reset();
        $this->assertNull(app(TenantManager::class)->id());

        $this->assertSame(2, $this->total($demandee, $sienne));
    }

    public function test_une_classe_hors_de_l_ecole_de_l_acteur_ne_rend_jamais_le_rapport_de_l_ecole(): void
    {
        $sienne = $this->ecole();
        $etrangere = $this->classe($this->ecole(), 'Ailleurs');
        $this->presences($this->seanceLocale($sienne, $this->classe($sienne, 'Ici')), $sienne, 3);
        $this->tenant($sienne);

        $this->expectException(ModelNotFoundException::class);
        $this->total($etrangere, $sienne);
    }

    public function test_la_requete_refuse_en_422_la_classe_d_une_autre_ecole(): void
    {
        Queue::fake();
        $sienne = $this->ecole();
        $etrangere = $this->classe($this->ecole(), 'Ailleurs');

        $this->asTenant($this->membre($sienne, 'coordinateur'))
            ->postJson(self::RAPPORT, ['classe_id' => $etrangere->getKey()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('classe_id');
        Queue::assertNothingPushed();
    }

    public function test_la_requete_accepte_une_classe_de_l_ecole_dans_les_deux_volets(): void
    {
        Queue::fake();
        $autonome = $this->ecole();
        $klassci = $this->ecole(InstitutionMode::Klassci);

        $this->asTenant($this->membre($autonome, 'coordinateur'))
            ->postJson(self::RAPPORT, ['classe_id' => $this->classe($autonome, 'Locale')->getKey()])
            ->assertStatus(202);
        $this->asTenant($this->membre($klassci, 'coordinateur'))
            ->postJson(self::RAPPORT, ['classe_id' => $this->classeMiroir($klassci, 'Miroir', 7701)->getKey()])
            ->assertStatus(202);
    }

    private function total(Classe $classe, Institution $ecole): int
    {
        $contexte = app(AttendanceReportContextBuilder::class)->build([
            'date_start' => self::JOUR,
            'date_end' => self::JOUR,
            'classe_id' => $classe->getKey(),
        ], (int) $ecole->getKey());

        return $contexte['stats']['total'];
    }

    private function totalSansFiltre(Institution $ecole): int
    {
        $contexte = app(AttendanceReportContextBuilder::class)->build([
            'date_start' => self::JOUR,
            'date_end' => self::JOUR,
        ], (int) $ecole->getKey());

        return $contexte['stats']['total'];
    }

    private function tenant(Institution $ecole): void
    {
        app(TenantManager::class)->set($ecole);
    }

    private function classeMiroir(Institution $ecole, string $libelle, int $klassciId): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_id' => $klassciId,
            'libelle' => $libelle,
        ]);
    }

    private function seanceLocale(Institution $ecole, Classe $classe): Seance
    {
        return Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'date_seance' => self::JOUR,
            'classe_id' => $classe->getKey(),
            'klassci_classe_id' => null,
        ]);
    }

    private function seanceMiroir(Institution $ecole, int $klassciClasseId): Seance
    {
        return Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'date_seance' => self::JOUR,
            'classe_id' => null,
            'klassci_classe_id' => $klassciClasseId,
        ]);
    }

    private function presences(Seance $seance, Institution $ecole, int $nombre): void
    {
        ESBTPAttendance::factory()->count($nombre)->create([
            'seance_id' => $seance->getKey(),
            'user_id' => fn () => User::factory()->student()->create(['institution_id' => $ecole->getKey()])->getKey(),
            'institution_id' => $ecole->getKey(),
            'status' => 'connected',
        ]);
    }
}
