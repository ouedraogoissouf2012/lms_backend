<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Models\Institution;
use App\Models\User;
use App\Services\Enrollment\KlassciEnrollmentSource;
use App\Services\Enrollment\TeacherMatieresLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * L'écrivain qui manquait : le lien enseignant ↔ matière (#712).
 *
 * `matiere_enseignant` existe depuis #? mais restait VIDE — mesure du
 * 2026-09-08 sur la base de dev : 0 ligne. Sans elle,
 * {@see KlassciEnrollmentSource} ne peut rien résoudre
 * et « Mes Classes » reste désespérément vide.
 *
 * La donnée existe pourtant : `MatiereSyncService` interroge `matieres` avec le
 * jeton de l'utilisateur CONNECTÉ à chaque login. Ce qu'il rapporte, ce sont
 * précisément « les matières de cet enseignant ». Il ne restait qu'à
 * l'enregistrer.
 *
 * ## Pourquoi une table dédiée, et pas `classe_matiere.enseignant_id`
 *
 * `classe_matiere` porte une ligne par couple (classe, matière), donc UN seul
 * enseignant. Y écrire l'utilisateur connecté ferait gagner le dernier à s'être
 * connecté, et effacerait le précédent — une fuite entre enseignants, du même
 * genre que #707. `matiere_enseignant` est un many-to-many : chaque enseignant
 * a ses propres lignes.
 */
final class TeacherMatieresLinkerTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => 9,
        ]);
    }

    public function test_it_records_the_link_for_each_matiere(): void
    {
        $this->linker()->link($this->teacher, [3, 1, 2]);

        self::assertSame(3, DB::table('matiere_enseignant')
            ->where('klassci_enseignant_id', 9)
            ->where('institution_id', $this->institution->id)
            ->where('status', 'active')
            ->count());
    }

    /**
     * La connexion se rejoue à chaque login : rien ne doit s'empiler.
     *
     * L'unique en base porte sur `(klassci_matiere_id, klassci_enseignant_id,
     * annee_universitaire_id)` — or cette dernière est NULLE ici, et SQL ne
     * dédoublonne pas les NULL. La clé de rapprochement doit donc être
     * EXPLICITE, jamais déléguée à la contrainte.
     */
    public function test_logging_in_twice_creates_no_duplicate(): void
    {
        $this->linker()->link($this->teacher, [3, 1]);
        $this->linker()->link($this->teacher, [3, 1]);

        self::assertSame(2, DB::table('matiere_enseignant')->count());
    }

    /**
     * Une matière qu'on n'enseigne plus passe `inactive` — sans quoi un
     * enseignant garderait éternellement des classes qui ne sont plus les
     * siennes.
     */
    public function test_a_matiere_no_longer_taught_becomes_inactive(): void
    {
        $this->linker()->link($this->teacher, [3, 1]);
        $this->linker()->link($this->teacher, [3]);

        self::assertSame('active', DB::table('matiere_enseignant')->where('klassci_matiere_id', 3)->value('status'));
        self::assertSame('inactive', DB::table('matiere_enseignant')->where('klassci_matiere_id', 1)->value('status'));
    }

    /**
     * L'INVARIANT le plus important de cette classe.
     *
     * Une liste VIDE ne signifie pas « cet enseignant n'enseigne plus rien » :
     * elle signifie « KLASSCI n'a rien dit ». Un appel dégradé, un 503, un
     * jeton expiré — et tout serait désactivé d'un coup.
     *
     * C'est exactement la faute qui transforme un réconciliateur en effaceur,
     * et ce dépôt en a déjà payé le prix : `StaleSeanceArchiver` archivait tout
     * un établissement parce qu'une clé morte rendait toujours `[]`.
     */
    public function test_an_empty_list_never_deactivates_anything(): void
    {
        $this->linker()->link($this->teacher, [3, 1]);
        $this->linker()->link($this->teacher, []);

        self::assertSame(2, DB::table('matiere_enseignant')->where('status', 'active')->count());
    }

    /**
     * Isolation : deux établissements peuvent porter le même
     * `klassci_enseignant_id`. Les liens ne doivent jamais fusionner.
     */
    public function test_the_same_klassci_teacher_stays_isolated_per_institution(): void
    {
        $ailleurs = Institution::factory()->create();
        $jumeau = User::factory()->teacher()->create([
            'institution_id' => $ailleurs->id,
            'klassci_enseignant_id' => 9,
        ]);

        $this->linker()->link($this->teacher, [3]);
        $this->linker()->link($jumeau, [3]);

        self::assertSame(2, DB::table('matiere_enseignant')->where('klassci_enseignant_id', 9)->count());
    }

    /**
     * Un compte sans `klassci_enseignant_id` n'a rien à lier — et ne doit pas
     * écrire une ligne orpheline que personne ne pourra jamais retrouver.
     */
    public function test_a_teacher_without_klassci_identity_writes_nothing(): void
    {
        $sansIdentite = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => null,
        ]);

        $this->linker()->link($sansIdentite, [3]);

        self::assertSame(0, DB::table('matiere_enseignant')->count());
    }

    private function linker(): TeacherMatieresLinker
    {
        return app(TeacherMatieresLinker::class);
    }
}
