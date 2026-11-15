<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Le filtre « enseignant » de la liste des séances à venir, côté gestion, en école
 * KLASSCI (`ManagerSeancesLocalFetcher`). `teacher_id` y est l'id d'enseignant
 * KLASSCI que l'annuaire rend : il désigne un ENSEIGNANT, dont les séances sont
 * celles qu'il gère (`VisioActorAuthorization::limitToTeacherSeances`), et jamais
 * celles qu'un compte LMS de même numéro a écrites (`created_by`, un id local).
 *
 * L'école autonome (`LocalSeancesListReader`, ids locaux des deux côtés) est
 * gardée par `SeancesEcoleIndependanteTest`.
 */
final class FiltreEnseignantDeLaGestionTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const URL = '/api/lms/seances/upcoming';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
    }

    /**
     * Le compte LMS de B porte le même numéro que l'id KLASSCI de A : les séances
     * que B a synchronisées ne sont pas listées sous A ; celle de A, si.
     */
    public function test_les_seances_d_un_autre_compte_de_meme_numero_ne_sont_pas_les_siennes(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $b = $this->enseignant($ecole, 40);
        $a = $this->enseignant($ecole, $b->id);
        $this->seance($ecole, 701, $b->id, $a->id);
        $this->seance($ecole, 702, 40, $b->id);

        self::assertSame([701], $this->seancesFiltrees($ecole, $b->id));
    }

    /** Une séance de A sans enseignant KLASSCI, reliée à A par son seul compte LMS : la sienne. */
    public function test_la_seance_de_l_enseignant_sans_identite_klassci_est_la_sienne(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $a = $this->enseignant($ecole, 9000);
        $this->seance($ecole, 711, null, $a->id);

        self::assertSame([711], $this->seancesFiltrees($ecole, 9000));
    }

    /**
     * Un enseignant sans compte LMS : ses séances par `klassci_enseignant_id` seul.
     * Le coordinateur qui a créé une autre séance porte ce numéro en id local : sa
     * séance n'est pas celle de l'enseignant.
     */
    public function test_sans_compte_lms_l_identite_klassci_seule(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $createur = $this->membre($ecole, 'coordinateur');
        $this->seance($ecole, 721, $createur->id, null);
        $this->seance($ecole, 722, 40, $createur->id);

        self::assertSame([721], $this->seancesFiltrees($ecole, $createur->id));
    }

    /** L'enseignant est cherché dans l'école du gestionnaire, jamais dans une autre. */
    public function test_l_enseignant_d_une_autre_ecole_n_est_pas_retenu(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $this->enseignant($this->ecole(InstitutionMode::Klassci), 9100);
        $this->seance($ecole, 731, 9100, null);

        self::assertSame([731], $this->seancesFiltrees($ecole, 9100));
    }

    /** @return list<int> les identifiants KLASSCI des séances listées, triés */
    private function seancesFiltrees(Institution $ecole, int $teacherId): array
    {
        $gestion = $this->membre($ecole, 'coordinateur');
        $gestion->forceFill(['klassci_token' => 'jeton-gestion'])->save();

        $lignes = $this->asTenant($gestion)->getJson(self::URL.'?teacher_id='.$teacherId)->assertOk()->json('data');
        $ids = array_map(static fn (array $ligne): int => (int) $ligne['id'], is_array($lignes) ? $lignes : []);
        sort($ids);

        return $ids;
    }

    private function enseignant(Institution $ecole, int $klassciEnseignantId): User
    {
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['klassci_enseignant_id' => $klassciEnseignantId])->save();

        return $enseignant;
    }

    /** Une séance miroir de KLASSCI, telle que la synchronisation l'écrit. */
    private function seance(Institution $ecole, int $klassciSeanceId, ?int $klassciEnseignantId, ?int $createdBy): void
    {
        Seance::factory()->for($ecole)->create([
            'klassci_seance_id' => $klassciSeanceId,
            'klassci_enseignant_id' => $klassciEnseignantId,
            'created_by' => $createdBy,
            'date_seance' => now()->addDay()->setTime(9, 0),
            'is_active' => true,
        ]);
    }
}
