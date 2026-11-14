<?php

declare(strict_types=1);

namespace Tests\Feature\Audience;

use App\Enums\InstitutionMode;
use App\Jobs\DispatchVisioNotification;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Audience\ClasseAudienceSource;
use App\Services\NotificationService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\LoggerInterface;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Une seule règle dit « qui est élève de cette séance », pour l'ADMISSION
 * (`containsStudent`) comme pour l'AUDIENCE (`studentsOf` : les avertissements, les
 * participants). Le port d'inscriptions répond dans les deux sens
 * (`localClasseIdsFor`, `localStudentIdsOf`), par l'autorité de l'école (D410) :
 *
 * - école autonome : le pivot actif seul ;
 * - école KLASSCI : le pivot actif, puis, pour un élève SANS ligne active sur aucune
 *   classe, le miroir `user_classes` de son établissement (C40).
 *
 * Le sens inverse choisit sa source par l'établissement de la CLASSE, jamais par le
 * tenant courant : les avertissements partent d'un job qui n'entre pas le tenant.
 */
final class UneSeuleRegleDElevesParSeanceTest extends TestCase
{
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const KLASSCI_CLASSE = 77;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /** 1. École autonome, pivot actif : admis et averti (témoin). */
    public function test_autonome_pivot_actif_admis_et_averti(): void
    {
        [$ecole, $classe] = $this->ecoleEtClasse(InstitutionMode::Standalone);
        $eleve = $this->eleve($ecole);
        $this->inscrire($eleve, $classe, 'actif');

        self::assertSame([true, true], $this->regle($this->seanceLocale($ecole, $classe), $eleve));
    }

    /** 2. École autonome, pivot inactif et miroir : ni admis, ni averti. */
    public function test_autonome_pivot_inactif_et_miroir_ni_admis_ni_averti(): void
    {
        [$ecole, $classe] = $this->ecoleEtClasse(InstitutionMode::Standalone);
        $eleve = $this->eleve($ecole);
        $this->inscrire($eleve, $classe, 'inactif');
        $this->miroir($eleve, $ecole->id);

        self::assertSame([false, false], $this->regle($this->seanceLocale($ecole, $classe), $eleve));
    }

    /** 3. École KLASSCI, aucune ligne de pivot, connu par le miroir : admis ET averti. */
    public function test_klassci_sans_ligne_et_miroir_admis_et_averti(): void
    {
        [$ecole, $classe] = $this->ecoleEtClasse(InstitutionMode::Klassci);
        $eleve = $this->eleve($ecole);
        $this->miroir($eleve, $ecole->id);

        self::assertSame([true, true], $this->regle($this->seanceLocale($ecole, $classe), $eleve));
    }

    /** 4. École KLASSCI, pivot actif : admis et averti (témoin). */
    public function test_klassci_pivot_actif_admis_et_averti(): void
    {
        [$ecole, $classe] = $this->ecoleEtClasse(InstitutionMode::Klassci);
        $eleve = $this->eleve($ecole);
        $this->inscrire($eleve, $classe, 'actif');

        self::assertSame([true, true], $this->regle($this->seanceLocale($ecole, $classe), $eleve));
    }

    /** 5. L'invariant, sur les deux volets : admis ⇔ averti, pour chaque profil. */
    public function test_l_admission_et_l_audience_disent_la_meme_chose(): void
    {
        foreach ([InstitutionMode::Klassci, InstitutionMode::Standalone] as $mode) {
            [$ecole, $classe] = $this->ecoleEtClasse($mode);
            $autre = $this->classe($ecole, '5e B');
            $profils = [
                'actif' => fn (User $e) => $this->inscrire($e, $classe, 'actif'),
                'inactif et miroir' => function (User $e) use ($classe, $ecole): void {
                    $this->inscrire($e, $classe, 'inactif');
                    $this->miroir($e, $ecole->id);
                },
                'sans ligne et miroir' => fn (User $e) => $this->miroir($e, $ecole->id),
                'actif ailleurs et miroir' => function (User $e) use ($autre, $ecole): void {
                    $this->inscrire($e, $autre, 'actif');
                    $this->miroir($e, $ecole->id);
                },
            ];
            $seance = $this->seanceLocale($ecole, $classe);

            foreach ($profils as $profil => $monter) {
                $eleve = $this->eleve($ecole);
                $monter($eleve);
                [$admis, $averti] = $this->regle($seance, $eleve);
                self::assertSame($admis, $averti, "{$mode->value}, {$profil} : l'admission et l'audience divergent");
            }
        }
    }

    /** 6. École KLASSCI, séance désignée par la classe KLASSCI (sans `classe_id`) : la même règle. */
    public function test_klassci_seance_designee_par_la_classe_klassci(): void
    {
        [$ecole, $classe] = $this->ecoleEtClasse(InstitutionMode::Klassci);
        $parLeMiroir = $this->eleve($ecole);
        $this->miroir($parLeMiroir, $ecole->id);
        $actif = $this->eleve($ecole);
        $this->inscrire($actif, $classe, 'actif');
        $seance = Seance::factory()->for($ecole)->create(['classe_id' => null, 'klassci_classe_id' => self::KLASSCI_CLASSE]);

        self::assertSame([true, true], $this->regle($seance, $parLeMiroir), 'connu par le miroir');
        self::assertSame([true, true], $this->regle($seance, $actif), 'au pivot actif');
    }

    /**
     * 7. Le job d'avertissement, SANS tenant, dans une école autonome : l'élève au
     * pivot actif est averti, celui du seul miroir ne l'est pas. Le sens inverse
     * choisit par l'établissement de la classe, pas par le tenant (absent ici).
     */
    public function test_le_job_sans_tenant_suit_l_ecole_de_la_classe(): void
    {
        [$ecole, $classe] = $this->ecoleEtClasse(InstitutionMode::Standalone);
        $actif = $this->eleve($ecole);
        $this->inscrire($actif, $classe, 'actif');
        $parLeMiroir = $this->eleve($ecole);
        $this->miroir($parLeMiroir, $ecole->id);
        $seance = $this->seanceLocale($ecole, $classe);
        $this->app->forgetScopedInstances();

        (new DispatchVisioNotification(DispatchVisioNotification::STARTING, $seance->id, [
            'seance_locale_id' => $seance->id, 'klassci_classe_id' => null, 'klassci_enseignant_id' => null,
            'matiere_nom' => 'Maths', 'enseignant_nom' => 'Enseignant',
        ]))->handle(app(NotificationService::class), app(LoggerInterface::class));

        $avertis = Notification::withoutGlobalScope('institution')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
        self::assertContains($actif->id, $avertis);
        self::assertNotContains($parLeMiroir->id, $avertis, 'le miroir n\'admet personne en école autonome');
    }

    /** 8. Précision 1 : « aucune ligne active » porte sur TOUTES les classes de l'élève. */
    public function test_klassci_un_eleve_actif_ailleurs_n_est_pas_compte_par_le_miroir(): void
    {
        [$ecole, $classe] = $this->ecoleEtClasse(InstitutionMode::Klassci);
        $eleve = $this->eleve($ecole);
        $this->inscrire($eleve, $this->classe($ecole, '5e B'), 'actif');
        $this->miroir($eleve, $ecole->id);

        self::assertSame([false, false], $this->regle($this->seanceLocale($ecole, $classe), $eleve));
    }

    /** 9. Précision 2 : la ligne du miroir doit porter l'établissement de l'élève. */
    public function test_klassci_une_ligne_du_miroir_d_un_autre_etablissement_ne_compte_pas(): void
    {
        [$ecole, $classe] = $this->ecoleEtClasse(InstitutionMode::Klassci);
        $eleve = $this->eleve($ecole);
        $this->miroir($eleve, Institution::factory()->create()->id);

        self::assertSame([false, false], $this->regle($this->seanceLocale($ecole, $classe), $eleve));
    }

    /** @return array{0: Institution, 1: Classe} */
    private function ecoleEtClasse(InstitutionMode $mode): array
    {
        $ecole = $this->ecole($mode);
        $classe = $this->classe($ecole, '6e A');
        $classe->forceFill(['klassci_id' => self::KLASSCI_CLASSE])->save();

        return [$ecole, $classe];
    }

    private function eleve(Institution $ecole): User
    {
        return $this->membre($ecole, 'etudiant');
    }

    /** Une ligne du miroir KLASSCI sur la classe d'id KLASSCI 77, rattachée à `$etablissement`. */
    private function miroir(User $eleve, int $etablissement): void
    {
        UserClass::query()->withoutGlobalScopes()->create([
            'user_id' => $eleve->id,
            'institution_id' => $etablissement,
            'klassci_classe_id' => self::KLASSCI_CLASSE,
            'classe_nom' => '6e A',
        ]);
    }

    private function seanceLocale(Institution $ecole, Classe $classe): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'classe_id' => $classe->id,
            'klassci_seance_id' => null,
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
            'created_by' => null,
        ]);
    }

    /**
     * [admis, averti], lus en requête de l'école de la séance.
     *
     * @return array{0: bool, 1: bool}
     */
    private function regle(Seance $seance, User $eleve): array
    {
        app(TenantManager::class)->set(Institution::query()->findOrFail($seance->institution_id));
        $audience = app(ClasseAudienceSource::class);
        $resultat = [
            $audience->containsStudent($seance, $eleve),
            $audience->studentsOf($seance)->contains(static fn (User $u): bool => $u->id === $eleve->id),
        ];
        app(TenantManager::class)->reset();

        return $resultat;
    }
}
