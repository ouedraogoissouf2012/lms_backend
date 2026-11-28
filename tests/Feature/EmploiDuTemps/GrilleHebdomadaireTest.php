<?php

declare(strict_types=1);

namespace Tests\Feature\EmploiDuTemps;

use App\Enums\InstitutionMode;
use App\Enums\TrainingSessionStatus;
use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\Program;
use App\Models\Seance;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1669 (PR 1, ARRÊT 1 validé le 28/11) — la grille hebdomadaire d'une classe, sans salle :
 * - un emploi du temps par classe, daté DANS la période de la classe ; ses lignes (jour, début, fin, matière du
 *   programme et enseignant, ou pause nommée, type) ; aucune ligne ne chevauche une autre ;
 * - l'enseignant d'une ligne est par défaut celui du programme ;
 * - la génération crée les séances datées (propriété de l'enseignant de la ligne), idempotente ; chaque séance passe
 *   par la règle des conflits : en conflit, elle est IGNORÉE et rapportée, jamais forcée ;
 * - modifier la grille retire les séances FUTURES de ses lignes changées, jamais une séance tenue ;
 * - alertes (classe sans emploi du temps actif, emploi du temps expiré) et copie ;
 * - la gestion compose ; une école KLASSCI compose dans KLASSCI (403).
 *
 * Jeton Bearer RÉEL, par `asTenant()` ; horloge figée un lundi.
 */
final class GrilleHebdomadaireTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const RACINE = '/api/lms/emplois-du-temps';

    private Institution $ecole;

    private User $coordinateur;

    private Classe $classe;

    /** @var array{maths: Matiere, physique: Matiere} */
    private array $matieres;

    /** @var array{maths: User, physique: User} */
    private array $enseignants;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-07 06:00:00');
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
        $this->classe = $this->classeDansLaPeriode('Terminale A');
        $this->matieres = ['maths' => $this->matiere($this->ecole, 'Maths'), 'physique' => $this->matiere($this->ecole, 'Physique')];
        $this->enseignants = ['maths' => $this->membre($this->ecole, 'enseignant'), 'physique' => $this->membre($this->ecole, 'enseignant')];
        foreach ($this->matieres as $cle => $matiere) {
            $this->programme($this->classe, $matiere, $this->enseignants[$cle]);
        }
    }

    public function test_un_emploi_du_temps_se_date_dans_la_periode_de_sa_classe(): void
    {
        $this->creer(['du' => '2026-12-07', 'au' => '2026-12-13'])->assertCreated()->assertJsonPath('data.en_vigueur', true);

        $this->creer(['du' => '2027-07-01', 'au' => '2027-07-31'])->assertUnprocessable()->assertJsonPath('reason', 'emploi_du_temps_hors_periode');
    }

    public function test_la_grille_refuse_une_matiere_hors_programme(): void
    {
        $id = $this->emploi();
        $hors = $this->matiere($this->ecole, 'Histoire');

        $this->lignes($id, [['jour' => 1, 'debut' => '08:00', 'fin' => '10:00', 'matiere_id' => $hors->id]])
            ->assertUnprocessable()->assertJsonPath('reason', 'matiere_hors_programme');
    }

    public function test_la_grille_refuse_deux_lignes_qui_se_chevauchent(): void
    {
        $id = $this->emploi();

        $this->lignes($id, [
            ['jour' => 1, 'debut' => '08:00', 'fin' => '10:00', 'matiere_id' => $this->matieres['maths']->id],
            ['jour' => 1, 'debut' => '09:30', 'fin' => '10:30', 'libelle' => 'Récréation'],
        ])->assertUnprocessable()->assertJsonPath('reason', 'grille_en_conflit');
    }

    public function test_l_enseignant_par_defaut_est_celui_du_programme(): void
    {
        $id = $this->emploi();
        $this->lignes($id, [['jour' => 1, 'debut' => '08:00', 'fin' => '10:00', 'matiere_id' => $this->matieres['maths']->id]])->assertOk();

        $this->asTenant($this->coordinateur)->getJson(self::RACINE.'/'.$id)->assertOk()
            ->assertJsonPath('data.lignes.0.enseignant_id', $this->enseignants['maths']->id);
    }

    public function test_generer_cree_les_seances_et_un_rejeu_ne_double_rien(): void
    {
        $id = $this->grilleType();

        $rapport = $this->generer($id)->assertOk()->json('data');
        self::assertSame([2, []], [$rapport['creees'], $rapport['ignorees']]);
        $lundi = Seance::query()->withoutGlobalScopes()->where('date_seance', '2026-12-07 08:00:00')->sole();
        self::assertSame([$this->enseignants['maths']->id, $this->classe->id, $this->matieres['maths']->id, '2026-12-07 10:00:00'],
            [(int) $lundi->created_by, (int) $lundi->classe_id, (int) $lundi->matiere_id, Carbon::parse($lundi->fin_seance)->format('Y-m-d H:i:s')]);

        $rejeu = $this->generer($id)->assertOk()->json('data');
        self::assertSame([0, ['deja_generee', 'deja_generee']], [$rejeu['creees'], array_column($rejeu['ignorees'], 'motif')]);
        self::assertSame(2, Seance::query()->withoutGlobalScopes()->where('institution_id', $this->ecole->id)->count());
    }

    public function test_une_seance_en_conflit_est_ignoree_jamais_forcee(): void
    {
        $id = $this->grilleType();
        $autre = $this->classeDansLaPeriode('Terminale B');
        Seance::factory()->for($this->ecole)->create([
            'date_seance' => '2026-12-07 08:30:00', 'fin_seance' => '2026-12-07 09:30:00', 'classe_id' => $autre->id,
            'created_by' => $this->enseignants['maths']->id, 'is_active' => true,
            'klassci_seance_id' => null, 'klassci_classe_id' => null, 'klassci_enseignant_id' => null,
        ]);

        $rapport = $this->generer($id)->assertOk()->json('data');

        self::assertSame(1, $rapport['creees']);
        self::assertSame([['2026-12-07', 'conflit']], array_map(static fn (array $i): array => [$i['date'], $i['motif']], $rapport['ignorees']));
    }

    public function test_modifier_la_grille_retire_les_seances_futures_seulement(): void
    {
        $id = $this->grilleType();
        $this->generer($id)->assertOk();
        $mercredi = Seance::query()->withoutGlobalScopes()->where('date_seance', '2026-12-09 10:00:00')->sole();
        ESBTPAttendance::factory()->create(['seance_id' => $mercredi->id, 'user_id' => $this->membre($this->ecole, 'etudiant')->id, 'institution_id' => $this->ecole->id]);

        $reponse = $this->lignes($id, [
            ['jour' => 2, 'debut' => '08:00', 'fin' => '10:00', 'matiere_id' => $this->matieres['maths']->id],
            ['jour' => 3, 'debut' => '14:00', 'fin' => '16:00', 'matiere_id' => $this->matieres['physique']->id],
        ])->assertOk();

        $reponse->assertJsonPath('data.retirees', 1);
        // Les séances se suppriment en douceur : le scope des supprimées est gardé, seul celui de l'école est levé.
        self::assertFalse(Seance::query()->withoutGlobalScope('institution')->where('date_seance', '2026-12-07 08:00:00')->exists(), 'La séance future non tenue est retirée.');
        self::assertTrue(Seance::query()->withoutGlobalScope('institution')->whereKey($mercredi->id)->exists(), 'Une séance qui a des présences reste.');
    }

    public function test_les_alertes(): void
    {
        $this->classeDansLaPeriode('Terminale B');
        $expire = $this->emploi(['du' => '2026-11-01', 'au' => '2026-11-30']);
        $this->emploi();

        $data = $this->asTenant($this->coordinateur)->getJson(self::RACINE.'/alertes')->assertOk()->json('data');

        self::assertSame(['Terminale B'], array_column($data['sans_emploi_du_temps'], 'libelle'));
        self::assertSame([$expire], array_column($data['expires'], 'id'));
    }

    public function test_copier_une_grille_vers_une_autre_classe(): void
    {
        $id = $this->grilleType();
        $soeur = $this->classeDansLaPeriode('Terminale C');
        $this->programme($soeur, $this->matieres['maths'], $this->enseignants['maths']);
        $this->programme($soeur, $this->matieres['physique'], $this->enseignants['physique']);
        $sansPhysique = $this->classeDansLaPeriode('Terminale D');
        $this->programme($sansPhysique, $this->matieres['maths'], $this->enseignants['maths']);

        $copie = $this->asTenant($this->coordinateur)->postJson(self::RACINE."/{$id}/copier", ['classe_id' => $soeur->id, 'du' => '2026-12-14', 'au' => '2026-12-20'])
            ->assertCreated()->json('data');
        self::assertSame([1, 1, 3], array_column($copie['lignes'], 'jour'));

        $this->asTenant($this->coordinateur)->postJson(self::RACINE."/{$id}/copier", ['classe_id' => $sansPhysique->id, 'du' => '2026-12-14', 'au' => '2026-12-20'])
            ->assertUnprocessable()->assertJsonPath('reason', 'matiere_hors_programme');
    }

    public function test_un_enseignant_ne_compose_pas(): void
    {
        $this->asTenant($this->enseignants['maths'])->postJson(self::RACINE, ['classe_id' => $this->classe->id, 'titre' => 'S1', 'du' => '2026-12-07', 'au' => '2026-12-13'])
            ->assertForbidden();
    }

    public function test_un_emploi_du_temps_d_une_autre_ecole_est_introuvable(): void
    {
        $id = $this->emploi();

        $this->asTenant($this->membre($this->ecole(), 'coordinateur'))->getJson(self::RACINE.'/'.$id)->assertNotFound();
    }

    public function test_une_ecole_klassci_compose_dans_klassci(): void
    {
        $coordinateur = $this->membre($this->ecole(InstitutionMode::Klassci), 'coordinateur');

        $this->asTenant($coordinateur)->getJson(self::RACINE.'/alertes')
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
    }

    /** Une grille type : lundi 8-10 maths, mercredi 10-12 physique, une pause lundi 10-10:15. */
    private function grilleType(): int
    {
        $id = $this->emploi();
        $this->lignes($id, [
            ['jour' => 1, 'debut' => '08:00', 'fin' => '10:00', 'matiere_id' => $this->matieres['maths']->id],
            ['jour' => 1, 'debut' => '10:00', 'fin' => '10:15', 'libelle' => 'Récréation'],
            ['jour' => 3, 'debut' => '10:00', 'fin' => '12:00', 'matiere_id' => $this->matieres['physique']->id, 'type_seance' => 'td'],
        ])->assertOk();

        return $id;
    }

    /** @param  array<string, string>  $dates */
    private function emploi(array $dates = []): int
    {
        return (int) $this->creer($dates)->assertCreated()->json('data.id');
    }

    /** @param  array<string, string>  $champs */
    private function creer(array $champs): TestResponse
    {
        return $this->asTenant($this->coordinateur)->postJson(self::RACINE, $champs + [
            'classe_id' => $this->classe->id, 'titre' => 'Semestre 1', 'du' => '2026-12-07', 'au' => '2026-12-13',
        ]);
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function lignes(int $id, array $lignes): TestResponse
    {
        return $this->asTenant($this->coordinateur)->putJson(self::RACINE."/{$id}/lignes", ['lignes' => $lignes]);
    }

    private function generer(int $id): TestResponse
    {
        return $this->asTenant($this->coordinateur)->postJson(self::RACINE."/{$id}/generer");
    }

    private function classeDansLaPeriode(string $libelle): Classe
    {
        $periode = TrainingSession::factory()->create([
            'institution_id' => $this->ecole->id,
            'program_id' => Program::factory()->create(['institution_id' => $this->ecole->id])->id,
            'status' => TrainingSessionStatus::Publiee,
            // Les cinq dates d'une période restent ordonnées (contrainte en base).
            'enrollment_opens_at' => '2026-08-01',
            'enrollment_closes_at' => '2026-08-31',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
            'certificate_available_at' => '2027-07-15',
        ]);
        $classe = $this->classe($this->ecole, $libelle);
        $classe->forceFill(['training_session_id' => $periode->id])->save();

        return $classe;
    }
}
