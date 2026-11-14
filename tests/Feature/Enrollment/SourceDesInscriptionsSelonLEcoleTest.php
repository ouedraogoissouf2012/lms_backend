<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Enrollment\EnrollmentSource;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * La source des inscriptions suit l'AUTORITÉ de l'école (D410), choisie à chaque
 * appel par l'école du compte :
 *
 * - école KLASSCI : le pivot actif, puis le repli sur le miroir `user_classes`
 *   (inchangé) ;
 * - école autonome : le pivot de l'établissement SEUL. Le miroir KLASSCI, gardé
 *   d'avant une bascule, n'admet plus personne (D57).
 * - sans tenant (un job) : la source de l'école du compte.
 *
 * Chaque cas d'accès se lit sur deux lecteurs du port : la liste des leçons
 * (`LessonReadScope`, contrôleur mémoïsé) et les chapitres d'une leçon
 * (`ChapterReadGate`).
 */
final class SourceDesInscriptionsSelonLEcoleTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const KLASSCI_CLASSE = 77;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    /** 1. Autonome, lignes toutes inactives et miroir : aucun accès, et le tableau dit D57. */
    public function test_autonome_lignes_inactives_et_miroir_aucun_acces(): void
    {
        [$ecole, $classe, $lecon] = $this->ecoleAvecLecon(InstitutionMode::Standalone);
        $eleve = $this->eleve($ecole);
        $this->inscrire($eleve, $classe, 'inactif');
        $this->miroir($eleve, $ecole);

        $this->assertAucunAcces($eleve, $lecon);
        $this->asTenant($eleve)->getJson('/api/proxy/me/dashboard')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'enrolment_not_active');
    }

    /** 2. Autonome, aucune ligne et miroir : aucun accès ; le tableau est vide (E2a), pas un refus. */
    public function test_autonome_sans_ligne_et_miroir_aucun_acces_et_tableau_vide(): void
    {
        [$ecole, , $lecon] = $this->ecoleAvecLecon(InstitutionMode::Standalone);
        $eleve = $this->eleve($ecole);
        $this->miroir($eleve, $ecole);

        $this->assertAucunAcces($eleve, $lecon);
        $this->asTenant($eleve)->getJson('/api/proxy/me/dashboard')
            ->assertOk()
            ->assertJsonPath('data.classe', null);
    }

    /** 3. Autonome, ligne active : l'accès (témoin). */
    public function test_autonome_ligne_active_acces(): void
    {
        [$ecole, $classe, $lecon] = $this->ecoleAvecLecon(InstitutionMode::Standalone);
        $eleve = $this->eleve($ecole);
        $this->inscrire($eleve, $classe, 'actif');

        $this->assertAcces($eleve, $lecon);
    }

    /** 4. KLASSCI, aucune ligne et miroir : l'accès par le repli (inchangé). */
    public function test_klassci_sans_ligne_et_miroir_acces_par_le_repli(): void
    {
        [$ecole, , $lecon] = $this->ecoleAvecLecon(InstitutionMode::Klassci);
        $eleve = $this->eleve($ecole);
        $this->miroir($eleve, $ecole);

        $this->assertAcces($eleve, $lecon);
    }

    /**
     * 5. KLASSCI, lignes inactives d'avant une bascule autonome → KLASSCI, et la classe
     * courante dans le miroir : l'accès. La règle rejetée (« repli seulement sans
     * aucune ligne », D410) le retirerait.
     */
    public function test_klassci_lignes_inactives_d_avant_la_bascule_et_miroir_acces(): void
    {
        [$ecole, $classe, $lecon] = $this->ecoleAvecLecon(InstitutionMode::Klassci);
        $eleve = $this->eleve($ecole);
        $this->inscrire($eleve, $classe, 'inactif');
        $this->miroir($eleve, $ecole);

        $this->assertAcces($eleve, $lecon);
    }

    /** 6. Règle 3 : la même route, au contrôleur mémoïsé, sert une école KLASSCI puis une école autonome. */
    public function test_la_meme_route_suit_l_autorite_de_chaque_ecole(): void
    {
        [$klassci, , $leconK] = $this->ecoleAvecLecon(InstitutionMode::Klassci);
        [$autonome, , $leconA] = $this->ecoleAvecLecon(InstitutionMode::Standalone);
        $eleveK = $this->eleve($klassci);
        $this->miroir($eleveK, $klassci);
        $eleveA = $this->eleve($autonome);
        $this->miroir($eleveA, $autonome);

        self::assertSame([$leconK->id], $this->leconsDe($eleveK));
        self::assertSame([], $this->leconsDe($eleveA), 'école autonome : le miroir n\'admet personne');
        self::assertNotContains($leconA->id, $this->leconsDe($eleveA));
    }

    /**
     * 7. Sans tenant (un job) : la source de l'ÉCOLE DU COMPTE. Un élève d'une école
     * autonome connu du seul miroir n'a aucune classe ; en école KLASSCI, le repli
     * s'applique. (Avant le choix par l'école du compte, ce test attendait la source
     * KLASSCI pour tous, sans tenant.)
     */
    public function test_sans_tenant_la_source_de_l_ecole_du_compte(): void
    {
        [$autonome] = $this->ecoleAvecLecon(InstitutionMode::Standalone);
        $eleveAutonome = $this->eleve($autonome);
        $this->miroir($eleveAutonome, $autonome);
        [$klassci, $classeKlassci] = $this->ecoleAvecLecon(InstitutionMode::Klassci);
        $eleveKlassci = $this->eleve($klassci);
        $this->miroir($eleveKlassci, $klassci);
        $this->app->forgetScopedInstances();

        self::assertSame([], app(EnrollmentSource::class)->localClasseIdsFor($eleveAutonome), 'école autonome : le pivot seul');
        self::assertSame([$classeKlassci->id], app(EnrollmentSource::class)->localClasseIdsFor($eleveKlassci), 'école KLASSCI : le repli');
    }

    /**
     * 8 (D4). Les deux méthodes du port suivent l'autorité : en école autonome, un
     * enseignant n'a de classes que par `classe_matiere`, jamais par le miroir
     * KLASSCI de ses matières (`matiere_enseignant`).
     */
    public function test_autonome_les_classes_d_un_enseignant_sans_le_miroir_klassci(): void
    {
        [$ecole, $classe] = $this->ecoleAvecLecon(InstitutionMode::Standalone);
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['klassci_enseignant_id' => 55])->save();
        $matiere = $this->matiere($ecole, 'Maths');
        $matiere->forceFill(['klassci_id' => 300])->save();
        $this->programme($classe, $matiere, null);
        DB::table('matiere_enseignant')->insert([
            'klassci_matiere_id' => 300, 'klassci_enseignant_id' => 55, 'institution_id' => $ecole->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(TenantManager::class)->set($ecole);

        self::assertSame([], app(EnrollmentSource::class)->classeIdsForTeacher($enseignant));
    }

    /** @return array{0: Institution, 1: Classe, 2: Lesson} */
    private function ecoleAvecLecon(InstitutionMode $mode): array
    {
        $ecole = $this->ecole($mode);
        $classe = $this->classe($ecole, '6e A');
        $classe->forceFill(['klassci_id' => self::KLASSCI_CLASSE])->save();
        $lecon = Lesson::factory()->published()->create(['institution_id' => $ecole->id, 'classe_id' => $classe->id]);

        return [$ecole, $classe, $lecon];
    }

    private function eleve(Institution $ecole): User
    {
        return $this->membre($ecole, 'etudiant');
    }

    /** Une ligne du miroir KLASSCI, telle que la connexion l'écrit, sur la classe d'id KLASSCI 77. */
    private function miroir(User $eleve, Institution $ecole): void
    {
        UserClass::query()->withoutGlobalScopes()->create([
            'user_id' => $eleve->id,
            'institution_id' => $ecole->id,
            'klassci_classe_id' => self::KLASSCI_CLASSE,
            'classe_nom' => '6e A',
        ]);
    }

    private function assertAcces(User $eleve, Lesson $lecon): void
    {
        self::assertSame([$lecon->id], $this->leconsDe($eleve), 'la liste des leçons');
        $this->asTenant($eleve)->getJson("/api/lessons/{$lecon->id}/chapters")->assertOk();
    }

    private function assertAucunAcces(User $eleve, Lesson $lecon): void
    {
        self::assertSame([], $this->leconsDe($eleve), 'la liste des leçons');
        $this->asTenant($eleve)->getJson("/api/lessons/{$lecon->id}/chapters")->assertNotFound();
    }

    /** @return list<int> */
    private function leconsDe(User $eleve): array
    {
        $lecons = $this->asTenant($eleve)->getJson('/api/lessons/my-courses')->assertOk()->json('data');

        return array_values(array_map(static fn (array $lecon): int => (int) $lecon['id'], is_array($lecons) ? $lecons : []));
    }
}
