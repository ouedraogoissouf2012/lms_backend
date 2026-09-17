<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\Recording\SeanceRecordingAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un formateur LOCAL est propriétaire de la séance qu'il a créée.
 *
 * ## Le défaut
 *
 * `SeanceRecordingAccessService::teacherOwnsSeance()` rendait `false` dès que
 * `seances.klassci_enseignant_id` était nul — ce qui est le cas **par
 * construction** d'une séance créée hors KLASSCI (`LocalSeanceCreator:28`).
 *
 * Conséquence : `canControl()` ne repose que sur cette propriété, donc un
 * formateur d'école autonome ne pouvait **ni démarrer, ni arrêter, ni lire**
 * l'enregistrement de sa propre séance.
 *
 * ## Le critère n'est pas inventé ici
 *
 * Le dépôt a déjà tranché la propriété d'une séance, dans
 * `ManagerSeancesLocalFetcher:47-50` :
 *
 *     $query->where('klassci_enseignant_id', $teacherId)
 *         ->orWhere('created_by', $teacherId);
 *
 * Propriétaire par l'identité KLASSCI **ou** par `created_by`.
 * `TeachingSeancesFetcher` écrit d'ailleurs les deux (lignes 207 et 212). Ce
 * service était le seul à ne connaître que la moitié KLASSCI : ce n'est pas un
 * manque d'architecture, c'est une incohérence entre deux services sur la même
 * question.
 *
 * ## Ce que ces tests gardent SURTOUT
 *
 * Élargir une garde d'accès est l'occasion classique de trop ouvrir. Les trois
 * derniers tests sont donc des **refus** : ils doivent rester rouges si le
 * correctif relâche quoi que ce soit.
 *
 * @see app/Services/Seances/ManagerSeancesLocalFetcher.php
 */
final class LocalTeacherOwnsRecordingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SeanceRecordingAccessService
    {
        return app(SeanceRecordingAccessService::class);
    }

    private function formateur(Institution $institution): User
    {
        return User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'enseignant',
            // Formateur LOCAL : aucune identité KLASSCI, le cas autonome.
            'klassci_id' => null,
            'klassci_enseignant_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function seanceLocale(Institution $institution, array $attributs = []): Seance
    {
        return Seance::factory()->create(array_merge([
            'institution_id' => $institution->getKey(),
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
        ], $attributs));
    }

    // ───────────────────────────────── ce qui doit s'ouvrir

    public function test_le_formateur_controle_l_enregistrement_de_sa_seance(): void
    {
        $institution = Institution::factory()->create();
        $formateur = $this->formateur($institution);
        $seance = $this->seanceLocale($institution, ['created_by' => $formateur->getKey()]);

        $this->assertTrue(
            $this->service()->canControl($seance, $formateur),
            'Sans cela, un formateur autonome ne peut ni démarrer ni arrêter '
            .'l\'enregistrement du cours qu\'il anime.'
        );
    }

    public function test_le_formateur_lit_l_enregistrement_de_sa_seance(): void
    {
        $institution = Institution::factory()->create();
        $formateur = $this->formateur($institution);
        $seance = $this->seanceLocale($institution, ['created_by' => $formateur->getKey()]);

        $this->assertTrue($this->service()->canRead($seance, $formateur));
    }

    // ───────────────────────────────── ce qui ne doit PAS bouger

    public function test_un_autre_formateur_de_la_meme_ecole_reste_refuse(): void
    {
        $institution = Institution::factory()->create();
        $proprietaire = $this->formateur($institution);
        $collegue = $this->formateur($institution);
        $seance = $this->seanceLocale($institution, ['created_by' => $proprietaire->getKey()]);

        $this->assertFalse(
            $this->service()->canControl($seance, $collegue),
            'Élargir la propriété au créateur ne doit pas l\'étendre à ses collègues.'
        );
    }

    public function test_un_formateur_d_une_autre_ecole_reste_refuse(): void
    {
        $institution = Institution::factory()->create();
        $voisine = Institution::factory()->create();
        $proprietaire = $this->formateur($institution);
        $etranger = $this->formateur($voisine);
        $seance = $this->seanceLocale($institution, ['created_by' => $proprietaire->getKey()]);

        $this->assertFalse($this->service()->canControl($seance, $etranger));
    }

    public function test_un_etudiant_createur_ne_devient_pas_proprietaire(): void
    {
        // `canControl` exige `isTeacher()` AVANT la propriété. Un étudiant qui
        // aurait créé une séance ne doit pas hériter du contrôle de
        // l'enregistrement — la garde de rôle reste en premier.
        $institution = Institution::factory()->create();
        $etudiant = User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'etudiant',
            'klassci_id' => null,
            'klassci_enseignant_id' => null,
        ]);
        $seance = $this->seanceLocale($institution, ['created_by' => $etudiant->getKey()]);

        $this->assertFalse($this->service()->canControl($seance, $etudiant));
    }

    public function test_une_seance_sans_createur_n_appartient_a_personne(): void
    {
        // Ce test garde le COMPORTEMENT — une séance sans créateur n'appartient
        // à personne — mais il ne prouve PAS que la garde `!== null` du service
        // soit nécessaire : `(int) null` vaut 0, qu'aucun identifiant n'atteint.
        // Falsification exercée : retirer cette garde ne le fait pas rougir.
        // On le dit plutôt que de laisser croire qu'il protège la ligne.
        $institution = Institution::factory()->create();
        $formateur = $this->formateur($institution);
        $seance = $this->seanceLocale($institution, ['created_by' => null]);

        $this->assertFalse(
            $this->service()->canControl($seance, $formateur),
            'Une séance sans créateur ne doit appartenir à personne, surtout pas à tous.'
        );
    }

    // ───────────────────────────────── le monde KLASSCI ne bouge pas

    public function test_la_propriete_par_identite_klassci_reste_intacte(): void
    {
        $institution = Institution::factory()->create();
        $formateur = User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'enseignant',
            'klassci_enseignant_id' => 4242,
        ]);
        $seance = Seance::factory()->create([
            'institution_id' => $institution->getKey(),
            'klassci_enseignant_id' => 4242,
            'created_by' => null,
        ]);

        $this->assertTrue(
            $this->service()->canControl($seance, $formateur),
            'Zéro régression : une école KLASSCI doit garder exactement son comportement.'
        );
    }
}
