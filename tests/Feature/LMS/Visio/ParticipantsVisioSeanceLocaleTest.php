<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\ESBTPAttendance;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1120 : `GET /lms/seances/{id}/visio-participants` ne cherchait la séance que par
 * `klassci_seance_id`. Une séance créée dans le LMS n'en a pas : la liste rendait
 * toujours 404, même pendant une visio active.
 *
 * La séance est désormais résolue par la règle unique `Seance::localIdFor` (celle de
 * la jonction : précédence à l'id local, bornée à l'établissement). Pour une séance
 * LOCALE, le roster vient de la règle locale (`ParticipantsDeSeanceLocale`), sans
 * appel KLASSCI. Le chemin d'une séance KLASSCI est inchangé.
 *
 * Vrai jeton.
 */
final class ParticipantsVisioSeanceLocaleTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_une_seance_locale_rend_ses_presents_et_ses_absents(): void
    {
        $ecole = $this->ecole(InstitutionMode::Standalone);
        $enseignant = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $present = $this->eleve($ecole, $classe, 'Ada Presente');
        $this->eleve($ecole, $classe, 'Bob Absent');
        $seance = $this->seanceLocale($ecole, $classe, $enseignant);
        ESBTPAttendance::factory()->create([
            'institution_id' => $ecole->id, 'seance_id' => $seance->id, 'user_id' => $present->id,
            'status' => 'connected', 'is_observer' => false, 'joined_at' => now()->subMinutes(10),
        ]);

        $reponse = $this->asTenant($enseignant)->getJson("/api/lms/seances/{$seance->id}/visio-participants")->assertOk();

        $eleves = collect((array) $reponse->json('data.students'))->keyBy('user_id');
        self::assertCount(2, $eleves, 'les élèves actifs de la classe');
        self::assertTrue((bool) $eleves->get($present->id)['is_present'], 'la présente');
        self::assertSame($seance->id, $reponse->json('data.seance.id'));
    }

    public function test_une_seance_inconnue_rend_404(): void
    {
        $ecole = $this->ecole(InstitutionMode::Standalone);

        $this->asTenant($this->membre($ecole, 'enseignant'))->getJson('/api/lms/seances/999999/visio-participants')->assertNotFound();
    }

    /** Une séance d'une AUTRE école reste introuvable (la règle unique borne l'établissement). */
    public function test_une_seance_locale_d_une_autre_ecole_rend_404(): void
    {
        $ailleurs = $this->ecole(InstitutionMode::Standalone);
        $seance = $this->seanceLocale($ailleurs, $this->classe($ailleurs, '5e B'), $this->membre($ailleurs, 'enseignant'));
        $ecole = $this->ecole(InstitutionMode::Standalone);

        $this->asTenant($this->membre($ecole, 'enseignant'))->getJson("/api/lms/seances/{$seance->id}/visio-participants")->assertNotFound();
    }

    /** Témoin : une séance KLASSCI désignée par son id KLASSCI reste servie, comme avant. */
    public function test_une_seance_klassci_reste_servie_par_son_id_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        Seance::factory()->for($ecole)->create(['klassci_seance_id' => 98765, 'visio_status' => 'active']);

        $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson('/api/lms/seances/98765/visio-participants')
            ->assertOk()
            ->assertJsonPath('data.seance.id', 98765);
    }

    private function eleve(Institution $ecole, Classe $classe, string $nom): User
    {
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['name' => $nom])->save();
        $this->inscrire($eleve, $classe, 'actif');

        return $eleve;
    }

    private function seanceLocale(Institution $ecole, Classe $classe, User $auteur): Seance
    {
        return Seance::factory()->for($ecole)->create([
            'created_by' => $auteur->id, 'classe_id' => $classe->id, 'date_seance' => now()->subMinutes(30),
            'is_active' => true, 'visio_enabled' => true, 'visio_status' => 'active', 'visio_started_at' => now()->subMinutes(20),
            'klassci_seance_id' => null, 'klassci_enseignant_id' => null, 'klassci_classe_id' => null, 'klassci_matiere_id' => null,
        ]);
    }
}
