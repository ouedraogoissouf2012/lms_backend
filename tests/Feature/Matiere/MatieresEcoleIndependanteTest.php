<?php

declare(strict_types=1);

namespace Tests\Feature\Matiere;

use App\Enums\InstitutionMode;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Services\KlassciProxyService;
use App\Services\Matiere\LocalMatiereDetailsReader;
use App\Services\Matiere\LocalTeacherMatieresReader;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Un enseignant d'une école indépendante crée une leçon À L'ÉCRAN (lot 1).
 *
 * Le chemin de l'écran : `/teacher/matieres` lit `lms/teacher/my-matieres`, la
 * carte mène à `/matieres/:id` qui lit `lms/matieres/{id}`, et « Créer » envoie
 * `POST /lessons` avec `matiere_id_local` et `classes_concernees[0].id`. Les
 * deux lectures rendaient 401 `klassci_token_missing` : un compte local n'a
 * jamais de jeton KLASSCI.
 *
 * Jeton Bearer RÉEL, par `asTenant()` : sans jeton, aucun tenant n'est résolu,
 * la fabrique choisirait la source KLASSCI, et l'isolation ne serait jamais
 * éprouvée.
 */
final class MatieresEcoleIndependanteTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const MES_MATIERES = '/api/lms/teacher/my-matieres';

    // ───────────────────────── « Mes matières »

    public function test_l_enseignant_lit_SES_matieres_et_les_classes_ou_il_les_enseigne(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $collegue = $this->membre($ecole, 'enseignant');
        [$sixieme, $cinquieme] = [$this->classe($ecole, '6e A'), $this->classe($ecole, '5e B')];
        $maths = $this->matiere($ecole, 'Maths');
        $svt = $this->matiere($ecole, 'SVT');
        $this->programme($sixieme, $maths, $moi);
        $this->programme($cinquieme, $maths, $collegue);
        $this->programme($cinquieme, $svt, $collegue);

        $reponse = $this->asTenant($moi)->getJson(self::MES_MATIERES)->assertOk();

        self::assertSame([[
            'id' => $maths->getKey(),
            'nom' => 'Maths',
            'coefficient' => 2,
            'classes' => [['id' => $sixieme->getKey(), 'nom' => '6e A']],
            'statistiques' => [
                'nombre_lessons_publiees' => 0,
                'nombre_lessons_brouillons' => 0,
                'nombre_seances' => null,
                'nombre_evaluations' => 0,
            ],
        ]], $reponse->json('data'));
    }

    public function test_les_compteurs_de_lecons_sont_ceux_de_la_matiere_locale(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);
        $this->lecon($classe, $maths, $moi, 'published');
        $this->lecon($classe, $maths, $moi, 'published');
        $this->lecon($classe, $maths, $moi, 'draft');
        $this->lecon($classe, $maths, $moi, 'archived');
        // Revue du lot 1 : la carte comptait les leçons de TOUS les
        // enseignants, et révélait les brouillons des collègues.
        $collegue = $this->membre($ecole, 'enseignant');
        $this->lecon($classe, $maths, $collegue, 'published');
        $this->lecon($classe, $maths, $collegue, 'draft');

        $this->asTenant($moi)->getJson(self::MES_MATIERES)
            ->assertOk()
            ->assertJsonPath('data.0.statistiques.nombre_lessons_publiees', 2)
            ->assertJsonPath('data.0.statistiques.nombre_lessons_brouillons', 1);
    }

    public function test_un_coordinateur_qui_n_enseigne_rien_recoit_une_liste_vide(): void
    {
        // La route dit « my » : la source KLASSCI rend ce qui est attribué à
        // CET appelant, jamais tout l'établissement.
        $ecole = $this->ecole();
        $this->programme($this->classe($ecole, '6e A'), $this->matiere($ecole, 'Maths'), $this->membre($ecole, 'enseignant'));

        $this->asTenant($this->membre($ecole, 'coordinateur'))
            ->getJson(self::MES_MATIERES)
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => []]);
    }

    // ───────────────────────── la page d'une matière

    public function test_l_enseignant_designe_ouvre_la_matiere_et_n_y_voit_que_ses_classes_et_ses_lecons(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $collegue = $this->membre($ecole, 'enseignant');
        [$sixieme, $cinquieme] = [$this->classe($ecole, '6e A'), $this->classe($ecole, '5e B')];
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($sixieme, $maths, $moi);
        $this->programme($cinquieme, $maths, $collegue);
        $mienne = $this->lecon($sixieme, $maths, $moi, 'draft');
        $this->lecon($cinquieme, $maths, $collegue, 'published');
        // Dans la MÊME classe : un filtre de classe à la place du filtre
        // d'auteur laisserait passer celle-ci (revue du lot 1).
        $this->lecon($sixieme, $maths, $collegue, 'published');

        $reponse = $this->asTenant($moi)->getJson($this->page($maths))->assertOk();

        $reponse->assertJsonPath('data.matiere_id_local', $maths->getKey())
            ->assertJsonPath('data.matiere.nom', 'Maths')
            ->assertJsonPath('data.classes_concernees', [['id' => $sixieme->getKey(), 'nom' => '6e A']])
            ->assertJsonPath('data.statistiques.nombre_seances_programmees', null)
            ->assertJsonPath('data.statistiques.nombre_lessons', 1);
        self::assertSame([$mienne->getKey()], array_column((array) $reponse->json('data.lessons'), 'id'));
    }

    public function test_un_enseignant_non_designe_recoit_404(): void
    {
        $ecole = $this->ecole();
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($this->classe($ecole, '6e A'), $maths, $this->membre($ecole, 'enseignant'));

        $this->asTenant($this->membre($ecole, 'enseignant'))->getJson($this->page($maths))->assertNotFound();
    }

    public function test_la_matiere_d_une_AUTRE_ecole_independante_reste_introuvable(): void
    {
        $sienne = $this->ecole();
        $autre = $this->ecole();
        $coordinateur = $this->membre($sienne, 'coordinateur');
        $voisine = $this->matiere($autre, 'Matière voisine');
        $this->programme($this->classe($autre, 'Classe voisine'), $voisine, $this->membre($autre, 'enseignant'));

        $reponse = $this->asTenant($coordinateur)->getJson($this->page($voisine))->assertNotFound();

        self::assertStringNotContainsString('voisine', (string) $reponse->getContent());
    }

    public function test_la_gestion_voit_toute_la_matiere_et_toutes_ses_lecons(): void
    {
        $ecole = $this->ecole();
        [$a, $b] = [$this->membre($ecole, 'enseignant'), $this->membre($ecole, 'enseignant')];
        [$sixieme, $cinquieme] = [$this->classe($ecole, '6e A'), $this->classe($ecole, '5e B')];
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($sixieme, $maths, $a);
        $this->programme($cinquieme, $maths, $b);
        $this->lecon($sixieme, $maths, $a, 'draft');
        $this->lecon($cinquieme, $maths, $b, 'published');

        $reponse = $this->asTenant($this->membre($ecole, 'coordinateur'))->getJson($this->page($maths))->assertOk();

        self::assertSame(['5e B', '6e A'], array_column((array) $reponse->json('data.classes_concernees'), 'nom'));
        self::assertCount(2, (array) $reponse->json('data.lessons'));
        self::assertCount(2, (array) $reponse->json('data.enseignants'));
    }

    public function test_l_apprenant_actif_lit_les_lecons_publiees_de_SA_classe_seulement(): void
    {
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        [$sixieme, $cinquieme] = [$this->classe($ecole, '6e A'), $this->classe($ecole, '5e B')];
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($sixieme, $maths, $enseignant);
        $this->programme($cinquieme, $maths, $enseignant);
        $publiee = $this->lecon($sixieme, $maths, $enseignant, 'published');
        $this->lecon($sixieme, $maths, $enseignant, 'draft');
        $this->lecon($cinquieme, $maths, $enseignant, 'published');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $sixieme, 'actif');

        $reponse = $this->asTenant($eleve)->getJson($this->page($maths))->assertOk();

        self::assertSame([$publiee->getKey()], array_column((array) $reponse->json('data.lessons'), 'id'));
        $reponse->assertJsonPath('data.classes_concernees', [['id' => $sixieme->getKey(), 'nom' => '6e A']]);
    }

    public function test_l_apprenant_INACTIF_ne_lit_pas_la_matiere(): void
    {
        // D57 : une inscription inactive n'ouvre aucun contenu.
        $ecole = $this->ecole();
        $enseignant = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '5e B');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $enseignant);
        $this->lecon($classe, $maths, $enseignant, 'published');
        $eleve = $this->membre($ecole, 'etudiant');
        $this->inscrire($eleve, $classe, 'inactif');

        $this->asTenant($eleve)->getJson($this->page($maths))->assertNotFound();
    }

    public function test_sans_tenant_resolu_les_sources_locales_bornent_quand_meme_a_l_ecole(): void
    {
        // Par HTTP, le scope global borne AUSSI : il ne peut donc pas prouver
        // le bornage explicite. Sans tenant résolu — un job, une commande —
        // ce scope est fail-open, et seul le filtre explicite tient.
        $sienne = $this->ecole();
        $autre = $this->ecole();
        $coordinateur = $this->membre($sienne, 'coordinateur');
        $voisin = $this->membre($autre, 'enseignant');
        $voisine = $this->matiere($autre, 'Matière voisine');
        $this->programme($this->classe($autre, 'Classe voisine'), $voisine, $voisin);
        $voisin->forceFill(['institution_id' => $sienne->getKey()])->save();
        // Une leçon de l'AUTRE école qui pointe une matière de la sienne : une
        // règle ancienne (`exists:matieres,id`) l'a permis (revue du lot 1).
        $sienneMatiere = $this->matiere($sienne, 'Maths');
        Lesson::factory()->published()->create([
            'institution_id' => $autre->getKey(),
            'classe_id' => null,
            'matiere_id' => $sienneMatiere->getKey(),
            'title' => 'Leçon étrangère',
        ]);
        app(TenantManager::class)->reset();

        self::assertNull(app(LocalMatiereDetailsReader::class)->getDetailsForUser((int) $voisine->getKey(), $coordinateur));
        self::assertSame([], app(LocalTeacherMatieresReader::class)->getMatieresForUser($voisin));
        $page = app(LocalMatiereDetailsReader::class)->getDetailsForUser((int) $sienneMatiere->getKey(), $coordinateur);
        self::assertNotNull($page);
        self::assertSame([], $page['lessons'], 'La leçon d une autre école a fuité sans tenant résolu.');
    }

    // ───────────────────────── le parcours de l'écran

    public function test_le_parcours_de_l_ecran_cree_une_lecon_avec_les_identifiants_rendus(): void
    {
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $classe = $this->classe($ecole, '6e A');
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($classe, $maths, $moi);

        // Ce que fait l'écran, pas à pas (useTeacherMatieres.js:37,
        // matiereDetails.js:116-124) : aucun identifiant n'est écrit à la main.
        $carte = $this->asTenant($moi)->getJson(self::MES_MATIERES)->json('data.0');
        self::assertIsArray($carte);
        $page = $this->asTenant($moi)->getJson('/api/lms/matieres/'.$carte['id'])->assertOk()->json('data');
        self::assertIsArray($page);

        $this->asTenant($moi)->postJson('/api/lessons', [
            'title' => 'La proportionnalité',
            'type' => 'cours',
            'status' => 'draft',
            'matiere_id' => $page['matiere_id_local'],
            'classe_id' => $page['classes_concernees'][0]['id'],
            'enseignant_id' => $moi->getKey(),
        ])->assertCreated();

        $apres = $this->asTenant($moi)->getJson('/api/lms/matieres/'.$carte['id'])->assertOk();
        self::assertSame(['La proportionnalité'], array_column((array) $apres->json('data.lessons'), 'title'));
        $this->assertDatabaseHas('lessons', [
            'title' => 'La proportionnalité',
            'matiere_id' => $maths->getKey(),
            'classe_id' => $classe->getKey(),
            'institution_id' => $ecole->getKey(),
        ]);
    }

    // ───────────────────────── les deux volets, sous un conteneur qui survit

    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        // Laravel garde le contrôleur sur l'objet Route. Une source reçue par
        // le CONSTRUCTEUR resterait celle de la première école servie : ce
        // test rougit si l'injection quitte la méthode.
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $sansJeton = $this->membre($klassci, 'enseignant');
        $independante = $this->ecole();
        $moi = $this->membre($independante, 'enseignant');
        $maths = $this->matiere($independante, 'Maths');
        $this->programme($this->classe($independante, '6e A'), $maths, $moi);

        $this->asTenant($sansJeton)->getJson(self::MES_MATIERES)
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'klassci_token_missing');
        $this->asTenant($moi)->getJson(self::MES_MATIERES)
            ->assertOk()
            ->assertJsonPath('data.0.nom', 'Maths');
        $this->asTenant($sansJeton)->getJson($this->page($maths))
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'klassci_token_missing');
        $this->asTenant($moi)->getJson($this->page($maths))->assertOk();
    }

    public function test_un_jeton_KLASSCI_perime_ne_fait_partir_aucun_appel(): void
    {
        // Le cas que #806 couvrait pour ces deux routes : un compte porteur
        // d'un jeton, dans une école indépendante. Il lit sa source locale, et
        // RIEN ne part vers KLASSCI — ni le jeton, ni une requête.
        $ecole = $this->ecole();
        $moi = $this->membre($ecole, 'enseignant');
        $moi->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $maths = $this->matiere($ecole, 'Maths');
        $this->programme($this->classe($ecole, '6e A'), $maths, $moi);
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
        Http::preventStrayRequests();

        $this->asTenant($moi)->getJson(self::MES_MATIERES)->assertOk()->assertJsonPath('data.0.nom', 'Maths');
        $this->asTenant($moi)->getJson($this->page($maths))->assertOk()->assertJsonPath('data.matiere.nom', 'Maths');
    }

    // ───────────────────────── harnais

    private function page(Matiere $matiere): string
    {
        return '/api/lms/matieres/'.$matiere->getKey();
    }
}
