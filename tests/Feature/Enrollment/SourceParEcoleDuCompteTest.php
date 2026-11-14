<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Enrollment\EnrollmentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Les deux méthodes du port qui partent d'un COMPTE (`localClasseIdsFor`,
 * `classeIdsForTeacher`) choisissent leur source par l'école du compte, comme le
 * sens inverse choisit par l'école de la classe. En requête, c'est l'école du
 * tenant (`ResolveInstitution` le pose sur l'établissement du compte) ; hors
 * requête, la règle tient sans tenant.
 */
final class SourceParEcoleDuCompteTest extends TestCase
{
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const KLASSCI_CLASSE = 77;

    /** La même instance du port, sans tenant, sert un élève KLASSCI puis un élève autonome. */
    public function test_la_meme_instance_suit_l_ecole_de_chaque_compte(): void
    {
        [$klassci, $classeKlassci] = $this->ecoleEtClasse(InstitutionMode::Klassci);
        [$autonome] = $this->ecoleEtClasse(InstitutionMode::Standalone);
        $eleveKlassci = $this->eleveDuMiroir($klassci);
        $eleveAutonome = $this->eleveDuMiroir($autonome);
        $this->app->forgetScopedInstances();
        $port = app(EnrollmentSource::class);

        self::assertSame([$classeKlassci->id], $port->localClasseIdsFor($eleveKlassci));
        self::assertSame([], $port->localClasseIdsFor($eleveAutonome));
    }

    /** `classeIdsForTeacher`, sans tenant : en école autonome, jamais par le miroir KLASSCI des matières. */
    public function test_les_classes_d_un_enseignant_suivent_son_ecole(): void
    {
        [$ecole, $classe] = $this->ecoleEtClasse(InstitutionMode::Standalone);
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['klassci_enseignant_id' => 55])->save();
        $matiere = $this->matiere($ecole, 'Maths');
        $matiere->forceFill(['klassci_id' => 300])->save();
        $this->programme($classe, $matiere, null);
        DB::table('matiere_enseignant')->insert([
            'klassci_matiere_id' => 300, 'klassci_enseignant_id' => 55, 'institution_id' => $ecole->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->forgetScopedInstances();

        self::assertSame([], app(EnrollmentSource::class)->classeIdsForTeacher($enseignant));
    }

    /** @return array{0: Institution, 1: Classe} */
    private function ecoleEtClasse(InstitutionMode $mode): array
    {
        $ecole = $this->ecole($mode);
        $classe = $this->classe($ecole, '6e A');
        $classe->forceFill(['klassci_id' => self::KLASSCI_CLASSE])->save();

        return [$ecole, $classe];
    }

    /** Un élève sans ligne de pivot, connu par le miroir KLASSCI de la classe 77. */
    private function eleveDuMiroir(Institution $ecole): User
    {
        $eleve = $this->membre($ecole, 'etudiant');
        UserClass::query()->withoutGlobalScopes()->create([
            'user_id' => $eleve->id, 'institution_id' => $ecole->id,
            'klassci_classe_id' => self::KLASSCI_CLASSE, 'classe_nom' => '6e A',
        ]);

        return $eleve;
    }
}
