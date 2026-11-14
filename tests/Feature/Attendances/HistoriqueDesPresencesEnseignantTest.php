<?php

declare(strict_types=1);

namespace Tests\Feature\Attendances;

use App\Enums\InstitutionMode;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\TenantManager;
use App\Services\Visio\VisioActorAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * L'historique des présences d'un ENSEIGNANT (`GET /lms/attendance/history`) ne
 * montre que les séances qu'il gère, selon la règle de la gestion de la visio
 * (`VisioActorAuthorization`) : un miroir KLASSCI par `klassci_enseignant_id`
 * (son `klassci_enseignant_id` ou son `klassci_id`), une séance du LMS par
 * `created_by`, dans son établissement.
 *
 * Avant, le filtre lisait une colonne `seances.enseignant_id` qui n'existe pas :
 * l'historique d'un enseignant échouait (500).
 *
 * La règle a deux formes, l'instance (`canManage`) et la requête
 * (`limitToTeacherSeances`) : un test d'équivalence les lie.
 */
final class HistoriqueDesPresencesEnseignantTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const URL = '/api/lms/attendance/history';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /** 1. École KLASSCI : l'enseignant lit les présences de son miroir, pas celles d'un collègue. */
    public function test_ecole_klassci_l_enseignant_lit_ses_seances_miroir(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $enseignant = $this->enseignant($ecole, ['klassci_id' => 42]);
        $sienne = $this->presence($this->seance($ecole, ['klassci_enseignant_id' => 42]));
        $this->presence($this->seance($ecole, ['klassci_enseignant_id' => 43]));

        self::assertSame([$sienne->id], $this->lignes($enseignant));
    }

    /** 2. École autonome : l'enseignant lit les présences de la séance qu'il a créée, pas celles d'un collègue. */
    public function test_ecole_autonome_l_enseignant_lit_les_seances_qu_il_a_creees(): void
    {
        $ecole = $this->ecole();
        $enseignant = $this->enseignant($ecole);
        $collegue = $this->enseignant($ecole);
        $sienne = $this->presence($this->seance($ecole, ['klassci_enseignant_id' => null, 'created_by' => $enseignant->id]));
        $this->presence($this->seance($ecole, ['klassci_enseignant_id' => null, 'created_by' => $collegue->id]));

        self::assertSame([$sienne->id], $this->lignes($enseignant));
    }

    /**
     * 3. L'équivalence : pour un enseignant, `canManage` (l'instance) et
     * `limitToTeacherSeances` (la requête) retiennent exactement les mêmes séances,
     * y compris une séance d'une AUTRE école au même `klassci_enseignant_id`.
     */
    public function test_la_forme_instance_et_la_forme_requete_disent_la_meme_chose(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $ailleurs = $this->ecole(InstitutionMode::Klassci);
        $enseignant = $this->enseignant($ecole, ['klassci_id' => 42, 'klassci_enseignant_id' => 77]);
        $collegue = $this->enseignant($ecole);
        $seances = [
            $this->seance($ecole, ['klassci_enseignant_id' => 42]),
            $this->seance($ecole, ['klassci_enseignant_id' => 77]),
            $this->seance($ecole, ['klassci_enseignant_id' => 43]),
            $this->seance($ecole, ['klassci_enseignant_id' => null, 'created_by' => $enseignant->id]),
            $this->seance($ecole, ['klassci_enseignant_id' => null, 'created_by' => $collegue->id]),
            $this->seance($ailleurs, ['klassci_enseignant_id' => 42]),
        ];
        $regle = app(VisioActorAuthorization::class);

        $parInstance = array_values(array_map(
            static fn (Seance $s): int => $s->id,
            array_filter($seances, static fn (Seance $s): bool => $regle->canManage($s, $enseignant)),
        ));
        $requete = Seance::query()->withoutGlobalScopes();
        $regle->limitToTeacherSeances($requete, $enseignant);
        /** @var list<int> $parRequete */
        $parRequete = $requete->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        self::assertSame([$seances[0]->id, $seances[1]->id, $seances[3]->id], $parInstance, 'la forme instance');
        self::assertSame($parInstance, $parRequete, 'la requête diverge de l\'instance');
    }

    /** 4. Témoins : l'élève lit ses présences, le coordinateur celles de l'établissement. */
    public function test_l_eleve_et_le_coordinateur_gardent_leur_historique(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $eleve = $this->membre($ecole, 'etudiant');
        $seance = $this->seance($ecole, ['klassci_enseignant_id' => 42]);
        $sienne = $this->presence($seance, $eleve);
        $autre = $this->presence($seance);

        self::assertSame([$sienne->id], $this->lignes($eleve));
        $vues = $this->lignes($this->membre($ecole, 'coordinateur'));
        sort($vues);
        self::assertSame([$sienne->id, $autre->id], $vues);
    }

    /** @param  array<string, mixed>  $attributs */
    private function enseignant(Institution $ecole, array $attributs = []): User
    {
        $enseignant = $this->membre($ecole, 'enseignant');
        if ($attributs !== []) {
            $enseignant->forceFill($attributs)->save();
        }

        return $enseignant;
    }

    /** @param  array<string, mixed>  $attributs */
    private function seance(Institution $ecole, array $attributs): Seance
    {
        return Seance::factory()->for($ecole)->create($attributs + ['created_by' => null]);
    }

    private function presence(Seance $seance, ?User $eleve = null): ESBTPAttendance
    {
        return ESBTPAttendance::factory()->create([
            'seance_id' => $seance->id,
            'user_id' => ($eleve ?? $this->membre(Institution::query()->findOrFail($seance->institution_id), 'etudiant'))->id,
            'institution_id' => $seance->institution_id,
        ]);
    }

    /** @return list<int> */
    private function lignes(User $compte): array
    {
        $lignes = $this->asTenant($compte)->getJson(self::URL)->assertOk()->json('data');

        return array_values(array_map(static fn (array $l): int => (int) $l['id'], is_array($lignes) ? $lignes : []));
    }
}
