<?php

declare(strict_types=1);

namespace Tests\Feature\Visio;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Models\UserClass;
use App\Services\Visio\Recording\SeanceRecordingAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #846 — l'apprenant revoit le cours qu'il a manqué.
 *
 * ## Le défaut
 *
 * `studentBelongsToSeanceClass` lisait `user_classes` EN DIRECT, et `canRead:25`
 * court-circuitait la porte dès que `klassci_classe_id` était nul. Or
 * `LocalSeanceCreator:32` écrit précisément `null`, et `user_classes` n'a qu'un
 * écrivain, alimenté par KLASSCI.
 *
 * Résultat mesuré : **tout apprenant d'une école autonome était privé des
 * enregistrements de ses propres cours**, sauf s'il avait été présent — la
 * troisième porte, les présences, restant ouverte.
 *
 * Or c'est précisément celui qui a MANQUÉ le cours que la relecture asynchrone
 * sert, différenciateur produit selon `PLAN:68`.
 *
 * ## Pourquoi ce fichier couvre AUSSI le chemin KLASSCI
 *
 * Mesure du 2026-09-19 avant d'écrire : `studentBelongsToSeanceClass`
 * n'apparaissait **que dans le service lui-même**. Aucun test, nulle part — y
 * compris pour le chemin KLASSCI qui fonctionne en production. Réécrire un
 * chemin non testé est l'occasion de le casser en silence.
 *
 * @see docs/adr/2026-09-19-846-01-seance-locale-et-sa-classe.md
 */
final class StudentReplayAccessTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SeanceRecordingAccessService
    {
        return app(SeanceRecordingAccessService::class);
    }

    private function etudiant(Institution $institution): User
    {
        return User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'etudiant',
            'klassci_id' => null,
            // Sans cela, la factory emet un identifiant d'enseignant du MEME
            // espace que celui de la seance (#682) : les deux valent 950000001
            // et `ownsByKlassciIdentity` prend l'apprenant pour le formateur.
            'klassci_enseignant_id' => null,
        ]);
    }

    // ───────────────────────── le monde autonome : ce que ce lot ouvre

    public function test_un_apprenant_local_revoit_le_cours_de_sa_classe(): void
    {
        $ecole = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null]);
        $eleve = $this->etudiant($ecole);
        $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);

        $seance = Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => $classe->getKey(),
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
        ]);

        self::assertTrue(
            $this->service()->canRead($seance, $eleve),
            'Sans cela, un apprenant d\'école autonome ne revoit aucun cours manqué.'
        );
    }

    public function test_un_apprenant_d_une_AUTRE_classe_reste_refuse(): void
    {
        $ecole = Institution::factory()->create();
        $sienne = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null]);
        $autre = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null]);
        $eleve = $this->etudiant($ecole);
        $sienne->etudiants()->attach($eleve->id, ['statut' => 'actif']);

        $seance = Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => $autre->getKey(),
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
        ]);

        self::assertFalse($this->service()->canRead($seance, $eleve));
    }

    public function test_un_apprenant_d_une_AUTRE_ecole_reste_refuse(): void
    {
        $ecole = Institution::factory()->create();
        $voisine = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null]);
        $etranger = $this->etudiant($voisine);

        $seance = Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => $classe->getKey(),
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
        ]);

        self::assertFalse($this->service()->canRead($seance, $etranger));
    }

    public function test_une_inscription_qui_TRAVERSE_les_ecoles_ne_donne_rien(): void
    {
        // DEFENSE EN PROFONDEUR. Sans ce cas, la borne d'etablissement du
        // service ne serait prouvee par AUCUN test : le composite refuse deja
        // l'etranger, faute d'inscription. Falsification exercee — retirer la
        // borne ne faisait alors rougir personne.
        //
        // Ici l'anomalie est posee explicitement : une ligne de `classe_etudiant`
        // qui franchit les etablissements, telle qu'une fuite ou une reprise de
        // donnees pourrait en produire. Le composite la rendrait ; la borne doit
        // l'arreter.
        $ecole = Institution::factory()->create();
        $voisine = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null]);
        $etranger = $this->etudiant($voisine);

        $classe->etudiants()->attach($etranger->id, ['statut' => 'actif']);

        $seance = Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => $classe->getKey(),
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
        ]);

        self::assertFalse(
            $this->service()->canRead($seance, $etranger),
            'Une inscription traversant les ecoles ne doit pas ouvrir la seance.'
        );
    }

    public function test_une_seance_sans_classe_n_ouvre_rien(): void
    {
        // Une séance locale peut naître avant que sa classe soit arrêtée.
        $ecole = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => null]);
        $eleve = $this->etudiant($ecole);
        $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);

        $seance = Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => null,
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
        ]);

        self::assertFalse($this->service()->canRead($seance, $eleve));
    }

    // ───────────────────────── le monde KLASSCI : NON-REGRESSION

    public function test_le_chemin_klassci_fonctionne_toujours(): void
    {
        // Ce chemin n'avait AUCUN test avant ce lot, et il tourne en production.
        $ecole = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => 7788]);
        $eleve = $this->etudiant($ecole);

        UserClass::query()->create([
            'user_id' => $eleve->id,
            'klassci_classe_id' => 7788,
            'institution_id' => $ecole->getKey(),
        ]);

        $seance = Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => null,
            'klassci_classe_id' => 7788,
        ]);

        self::assertTrue(
            $this->service()->canRead($seance, $eleve),
            'Le chemin KLASSCI existait et fonctionnait : le reroutage ne doit pas le casser.'
        );
        self::assertNotNull($classe->id);
    }

    public function test_la_porte_klassci_marche_sur_la_donnee_REELLE_de_production(): void
    {
        // LA REPARATION. StudentClassSynchronizer:96 — seul ecrivain de
        // `user_classes` — n'ecrit PAS `institution_id`. L'ancienne requete
        // filtrait dessus et rendait donc TOUJOURS faux en production : la porte
        // etudiant etait morte, seules les presences ouvraient l'acces.
        //
        // Ce test pose la ligne exactement comme la production la pose.
        $ecole = Institution::factory()->create();
        $eleve = $this->etudiant($ecole);

        UserClass::query()->create([
            'user_id' => $eleve->id,
            'klassci_classe_id' => 55,
            'classe_nom' => 'Classe 55',
            // PAS d'institution_id : c'est le point du test.
        ]);

        $seance = Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => null,
            'klassci_classe_id' => 55,
            'klassci_enseignant_id' => null,
        ]);

        self::assertTrue(
            $this->service()->canRead($seance, $eleve),
            'Sur la donnee reellement ecrite en production, la porte doit s ouvrir.'
        );
    }

    public function test_un_identifiant_klassci_d_une_autre_ecole_ne_traverse_pas(): void
    {
        // `klassci_id` n'est unique QUE par institution (#707) : la traduction
        // du miroir doit rester bornée à l'établissement de la séance.
        $ecole = Institution::factory()->create();
        $voisine = Institution::factory()->create();
        Classe::factory()->create(['institution_id' => $voisine->getKey(), 'klassci_id' => 4242]);
        $eleve = $this->etudiant($ecole);

        UserClass::query()->create([
            'user_id' => $eleve->id,
            'klassci_classe_id' => 4242,
            'institution_id' => $voisine->getKey(),
        ]);

        $seance = Seance::factory()->create([
            'institution_id' => $ecole->getKey(),
            'classe_id' => null,
            'klassci_classe_id' => 4242,
            'klassci_enseignant_id' => null,
        ]);

        self::assertFalse($this->service()->canRead($seance, $eleve));
    }
}
