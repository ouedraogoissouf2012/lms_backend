<?php

declare(strict_types=1);

namespace Tests\Feature\Classe;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Classe\LocalClasseDetailsReader;
use App\Services\Classe\LocalTeacherClassesReader;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Les classes d'un enseignant et la fiche d'une classe, dans une école créée
 * depuis le LMS (lot 2, ADR-EI-01 et ADR-760-01 amendé).
 *
 * `lms/teacher/classes` rendait une liste VIDE : son repli local écartait toute
 * classe sans `klassci_id`. La fiche refusait par ses deux portes : 401 par la
 * porte historique, 409 « créée localement » par la porte locale — celle où mène
 * l'onglet Classes de la page matière. Le roster seul rendait 401.
 *
 * L'école créée depuis KLASSCI garde ses sources : les cas qui la jouent ici
 * prouvent que le choix de la source ne la touche pas.
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class ClassesEcoleIndependanteTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const MES_CLASSES = '/api/lms/teacher/classes';

    // ───────────────────────── « Mes classes »

    public function test_l_enseignant_lit_SES_classes_avec_l_effectif_actif(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $collegue = $this->membre($ecole, 'enseignant');
        // Créées dans le DÉSORDRE : le tri par libellé doit être éprouvé.
        $cinquieme = $this->classe($ecole, '5e B');
        $sixieme = $this->classe($ecole, '6e A');
        $quatrieme = $this->classe($ecole, '4e C');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($sixieme, $maths, $moi);
        $this->programme($cinquieme, $maths, $moi);
        $this->programme($quatrieme, $maths, $collegue);
        $this->inscrire($this->membre($ecole, 'etudiant'), $sixieme, 'actif');
        $this->inscrire($this->membre($ecole, 'etudiant'), $sixieme, 'actif');
        $this->inscrire($this->membre($ecole, 'etudiant'), $sixieme, 'inactif');

        $reponse = $this->asTenant($moi)->getJson(self::MES_CLASSES)->assertOk();

        self::assertSame([
            ['id' => $cinquieme->getKey(), 'libelle' => '5e B', 'code' => $cinquieme->code, 'effectif' => null, 'effectif_actuel' => 0],
            ['id' => $sixieme->getKey(), 'libelle' => '6e A', 'code' => $sixieme->code, 'effectif' => null, 'effectif_actuel' => 2],
        ], $reponse->json('data'));
    }

    public function test_un_coordinateur_qui_n_enseigne_rien_recoit_une_liste_vide(): void
    {
        $ecole = $this->ecole();
        $this->programme($this->classe($ecole, '6e A'), $this->matiere($ecole, 'Maths'), $this->membre($ecole, 'enseignant'));

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::MES_CLASSES)
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => []]);
    }

    // ───────────────────────── la fiche, par ses deux portes

    /** @return iterable<string, array{0: string}> */
    public static function portes(): iterable
    {
        yield 'porte historique' => ['/api/lms/classes/'];
        yield 'porte locale' => ['/api/lms/classes/local/'];
    }

    public function test_les_deux_portes_rendent_la_meme_fiche_pour_le_meme_id_local(): void
    {
        [$ecole, $classe, $moi] = $this->classeEnseignee();

        $historique = $this->asTenant($moi)->getJson('/api/lms/classes/'.$classe->getKey())->assertOk();
        $locale = $this->asTenant($moi)->getJson('/api/lms/classes/local/'.$classe->getKey())->assertOk();

        self::assertSame($historique->json('data'), $locale->json('data'));
        self::assertSame($ecole->getKey(), $classe->institution_id);
    }

    #[DataProvider('portes')]
    public function test_l_enseignant_designe_lit_la_fiche_et_le_roster_ACTIF(string $porte): void
    {
        [$ecole, $classe, $moi] = $this->classeEnseignee();
        $actif = $this->membre($ecole, 'etudiant');
        $inactif = $this->membre($ecole, 'etudiant');
        $this->inscrire($actif, $classe, 'actif');
        $this->inscrire($inactif, $classe, 'inactif');

        $reponse = $this->asTenant($moi)->getJson($porte.$classe->getKey())->assertOk();

        $reponse->assertJsonPath('data.classe.id', $classe->getKey())
            ->assertJsonPath('data.classe.name', '6e A')
            ->assertJsonPath('data.matieres_disponibles.0.nom', 'Maths')
            ->assertJsonPath('data.matieres_disponibles.0.enseignants.0.id', $moi->getKey())
            ->assertJsonPath('data.statistiques.nombre_etudiants', 1)
            ->assertJsonPath('data.statistiques.nombre_matieres', 1)
            ->assertJsonPath('data.statistiques.capacite_classe', null)
            ->assertJsonPath('data.statistiques.nombre_seances_semaine', null)
            ->assertJsonPath('data.evaluations_programmees', [])
            ->assertJsonPath('data.emploi_temps_semaine', []);
        self::assertSame([$actif->getKey()], array_column((array) $reponse->json('data.etudiants'), 'id'));
        $reponse->assertJsonPath('data.etudiants.0.statut', 'actif');
    }

    #[DataProvider('portes')]
    public function test_la_gestion_lit_toute_classe_de_l_ecole(string $porte): void
    {
        [$ecole, $classe] = $this->classeEnseignee();

        $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson($porte.$classe->getKey())->assertOk();
    }

    #[DataProvider('portes')]
    public function test_un_enseignant_non_designe_recoit_404(string $porte): void
    {
        [$ecole, $classe] = $this->classeEnseignee();

        $this->asTenant($this->membre($ecole, 'enseignant'))
            ->getJson($porte.$classe->getKey())
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Classe non trouvée']);
    }

    #[DataProvider('portes')]
    public function test_l_apprenant_actif_lit_sa_classe_SANS_le_roster(string $porte): void
    {
        [$ecole, $classe] = $this->classeEnseignee();
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $classe, 'actif');
        $this->inscrire($this->membre($ecole, 'etudiant'), $classe, 'actif');

        $this->asTenant($eleve)->getJson($porte.$classe->getKey())
            ->assertOk()
            ->assertJsonPath('data.etudiants', []);
    }

    #[DataProvider('portes')]
    public function test_l_apprenant_INACTIF_ne_lit_pas_la_classe(string $porte): void
    {
        // D57 : une inscription inactive n'ouvre rien.
        [$ecole, $classe] = $this->classeEnseignee();
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $classe, 'inactif');

        $this->asTenant($eleve)->getJson($porte.$classe->getKey())->assertNotFound();
    }

    #[DataProvider('portes')]
    public function test_la_classe_d_une_AUTRE_ecole_reste_introuvable(string $porte): void
    {
        $sienne = $this->ecole();
        $autre = $this->ecole();
        $voisine = $this->classe($autre, 'Classe voisine');
        $this->programme($voisine, $this->matiere($autre, 'Matière voisine'), $this->membre($autre, 'enseignant'));

        $reponse = $this->asTenant($this->membre($sienne, 'coordinateur'))
            ->getJson($porte.$voisine->getKey())
            ->assertNotFound();

        self::assertStringNotContainsString('voisine', (string) $reponse->getContent());
    }

    // ───────────────────────── le roster seul

    public function test_le_roster_seul_rend_les_actifs_au_personnel(): void
    {
        [$ecole, $classe, $moi] = $this->classeEnseignee();
        $actif = $this->membre($ecole, 'etudiant');
        $this->inscrire($actif, $classe, 'actif');
        $this->inscrire($this->membre($ecole, 'etudiant'), $classe, 'inactif');

        $reponse = $this->asTenant($moi)->getJson('/api/lms/classes/'.$classe->getKey().'/etudiants')->assertOk();

        self::assertSame([$actif->getKey()], array_column((array) $reponse->json('data.etudiants'), 'id'));
        $reponse->assertJsonPath('data.total', 1);
    }

    public function test_le_roster_seul_reste_ferme_a_l_apprenant(): void
    {
        [$ecole, $classe] = $this->classeEnseignee();
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $classe, 'actif');

        $this->asTenant($eleve)->getJson('/api/lms/classes/'.$classe->getKey().'/etudiants')->assertForbidden();
    }

    // ───────────────────────── le chemin d'écran du lot 1

    public function test_l_onglet_classes_de_la_page_matiere_ouvre_la_fiche(): void
    {
        [, $classe, $moi, $maths] = $this->classeEnseignee();

        $id = $this->asTenant($moi)->getJson('/api/lms/matieres/'.$maths->getKey())
            ->assertOk()
            ->json('data.classes_concernees.0.id');

        // `useMatiereDetails.js:258` : la porte LOCALE, qui rendait 409.
        $this->asTenant($moi)->getJson('/api/lms/classes/local/'.$id)
            ->assertOk()
            ->assertJsonPath('data.classe.id', $classe->getKey());
    }

    // ───────────────────────── les deux volets, sous un conteneur qui survit

    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        // Laravel garde le contrôleur sur l'objet Route : une source reçue par
        // le constructeur resterait celle de la première école servie.
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $sansJeton = $this->membre($klassci, 'enseignant');
        $classeKlassci = Classe::factory()->create(['institution_id' => $klassci->getKey()]);
        [, $classe, $moi] = $this->classeEnseignee();

        $this->asTenant($sansJeton)->getJson('/api/lms/classes/'.$classeKlassci->getKey())
            ->assertUnauthorized()->assertJsonPath('reason', 'klassci_token_missing');
        $this->asTenant($moi)->getJson('/api/lms/classes/'.$classe->getKey())->assertOk();
        $this->asTenant($sansJeton)->getJson('/api/lms/classes/'.$classeKlassci->getKey().'/etudiants')
            ->assertUnauthorized()->assertJsonPath('reason', 'klassci_token_missing');
        $this->asTenant($moi)->getJson('/api/lms/classes/'.$classe->getKey().'/etudiants')->assertOk();
        $this->asTenant($sansJeton)->getJson('/api/lms/classes/local/'.$classeKlassci->getKey())
            ->assertUnauthorized()->assertJsonPath('reason', 'klassci_token_missing');
        $this->asTenant($moi)->getJson('/api/lms/classes/local/'.$classe->getKey())->assertOk();
        // La source KLASSCI rend une liste vide à ce compte sans jeton ni
        // classe miroitée ; la source locale rend la classe désignée.
        $this->asTenant($sansJeton)->getJson(self::MES_CLASSES)->assertExactJson(['success' => true, 'data' => []]);
        $this->asTenant($moi)->getJson(self::MES_CLASSES)->assertOk()->assertJsonPath('data.0.id', $classe->getKey());
    }

    public function test_la_porte_locale_garde_son_409_dans_une_ecole_KLASSCI(): void
    {
        // ADR-760-01 amendé : le 409 « créée localement » ne vaut plus que
        // dans une école créée depuis KLASSCI — et il y vaut toujours.
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $locale = Classe::factory()->create(['institution_id' => $klassci->getKey(), 'klassci_id' => null]);
        $compte = User::factory()->create(['institution_id' => $klassci->getKey(), 'role' => 'coordinateur']);

        $this->asTenant($compte)->getJson('/api/lms/classes/local/'.$locale->getKey())
            ->assertStatus(409)
            ->assertJsonPath('reason', 'classe_local_only');
    }

    public function test_un_jeton_KLASSCI_perime_ne_fait_partir_aucun_appel(): void
    {
        [, $classe, $moi] = $this->classeEnseignee();
        $moi->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });

        $this->asTenant($moi)->getJson(self::MES_CLASSES)->assertOk()->assertJsonPath('data.0.id', $classe->getKey());
        $this->asTenant($moi)->getJson('/api/lms/classes/'.$classe->getKey())->assertOk();
        $this->asTenant($moi)->getJson('/api/lms/classes/'.$classe->getKey().'/etudiants')->assertOk();
    }

    public function test_sans_tenant_resolu_les_sources_locales_bornent_quand_meme_a_l_ecole(): void
    {
        // Par HTTP, le scope global borne aussi : il ne prouve pas le filtre
        // explicite. Sans tenant résolu, ce scope est fail-open.
        $sienne = $this->ecole();
        $autre = $this->ecole();
        $coordinateur = $this->membre($sienne, 'coordinateur');
        $voisin = $this->membre($autre, 'enseignant');
        $voisine = $this->classe($autre, 'Classe voisine');
        $this->programme($voisine, $this->matiere($autre, 'Matière voisine'), $voisin);
        $voisin->forceFill(['institution_id' => $sienne->getKey()])->save();
        app(TenantManager::class)->reset();

        $fiche = app(LocalClasseDetailsReader::class)->getDetailsForUser((int) $voisine->getKey(), $coordinateur);

        self::assertSame(404, $fiche['status']);
        self::assertSame([], app(LocalTeacherClassesReader::class)->listFor($voisin));
    }

    public function test_sans_tenant_le_roster_et_le_programme_restent_bornes_a_l_ecole(): void
    {
        // Une inscription et un rattachement forgés vers une AUTRE école :
        // le produit les refuse, le bornage explicite doit les écarter quand
        // même, sans tenant résolu (scope global fail-open).
        [$ecole, $classe, $moi] = $this->classeEnseignee();
        $autre = $this->ecole();
        $intrus = $this->membre($autre, 'etudiant');
        $this->inscrire($intrus, $classe, 'actif');
        $this->rattacherSansGarde($classe, $this->matiere($autre, 'Matière voisine'), $moi);
        $coordinateur = $this->membre($ecole, 'coordinateur');
        app(TenantManager::class)->reset();

        $fiche = app(LocalClasseDetailsReader::class)->getDetailsForUser((int) $classe->getKey(), $coordinateur);

        self::assertSame(200, $fiche['status']);
        $donnees = (array) $fiche['payload']['data'];
        self::assertSame([], (array) $donnees['etudiants']);
        self::assertSame(['Maths'], array_column((array) $donnees['matieres_disponibles'], 'nom'));
    }

    public function test_sans_tenant_une_matiere_d_une_autre_ecole_ne_designe_personne(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $this->rattacherSansGarde($classe, $this->matiere($this->ecole(), 'Matière voisine'), $moi);
        app(TenantManager::class)->reset();

        self::assertSame([], app(LocalTeacherClassesReader::class)->listFor($moi));
        self::assertSame(404, app(LocalClasseDetailsReader::class)->getDetailsForUser((int) $classe->getKey(), $moi)['status']);
    }

    // ───────────────────────── harnais

    /**
     * Un rattachement écrit SANS le service du produit, qui refuse une matière
     * d'une autre école : seul moyen d'éprouver le bornage du lecteur.
     */
    private function rattacherSansGarde(Classe $classe, Matiere $matiere, User $enseignant): void
    {
        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->getKey(),
            'matiere_id' => $matiere->getKey(),
            'enseignant_id' => $enseignant->getKey(),
            'institution_id' => $classe->institution_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Une classe de 6e A où « moi » enseigne les maths.
     *
     * @return array{0: Institution, 1: Classe, 2: User, 3: Matiere}
     */
    private function classeEnseignee(): array
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);

        return [$ecole, $classe, $moi, $maths];
    }
}
