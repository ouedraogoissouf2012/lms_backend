<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\ClasseEtudiantStatut;
use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Evaluation\Student\LearnerEvaluationScope;
use App\Services\Evaluation\Teacher\LocalEvaluationResultsReader;
use App\Services\Evaluation\Teacher\NomsDesAuteurs;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * D72, E3 : dans une école créée depuis le LMS, l'enseignant voit les
 * résultats de sa classe, nomme les copies, note et prévisualise.
 *
 * Avant : `results-by-class` répondait 401 (`klassci_token_missing`), son
 * roster venant de KLASSCI ; les copies étaient regroupées et nommées par
 * `klassci_etudiant_id`, nul pour toute copie locale (C26) ; la
 * synchronisation répondait 400 sans motif.
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class EnseignantCorrigeSesEvaluationsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    // ───────────────────────── résultats, école créée depuis le LMS

    public function test_chaque_eleve_de_la_classe_a_sa_ligne_sa_note_et_son_statut(): void
    {
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $awa = $this->eleve($ecole, $classe, 'Awa Koné');
        $yao = $this->eleve($ecole, $classe, 'Yao Kouassi');
        $evaluation = $this->evaluation($classe, $maths, $enseignant);
        $this->copie($evaluation, $awa, 'soumis', '15.00');
        $this->copie($evaluation, $yao, 'corrige', '8.00');
        $this->aucunAppelKlassci();

        $reponse = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/results-by-class', 'get');
        $reponse->assertOk()
            ->assertJsonPath('data.evaluation.id', $evaluation->getKey())
            ->assertJsonPath('data.evaluation.classe.nom', '6e A')
            ->assertJsonPath('data.evaluation.matiere.nom', 'Maths')
            ->assertJsonPath('data.evaluation.questions_count', 1);

        // Deux copies sans identité KLASSCI, deux lignes distinctes : un
        // regroupement par `klassci_etudiant_id` les fondrait en une seule.
        self::assertSame([
            $awa->getKey() => ['Awa Koné', '15.00', 'soumis'],
            $yao->getKey() => ['Yao Kouassi', '8.00', 'corrige'],
        ], $this->lignes($reponse->json('data.resultats')));
    }

    public function test_un_inscrit_sans_copie_est_non_passee_et_un_inactif_sans_copie_n_apparait_pas(): void
    {
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $awa = $this->eleve($ecole, $classe, 'Awa Koné');
        $this->eleve($ecole, $classe, 'Ina Ctif', 'inactif');
        $ailleurs = $this->eleve($ecole, $this->classe($ecole, '5e B'), 'Ali Leurs');
        // Désinscrite APRÈS avoir remis : hors du roster, mais sa copie reste
        // visible, marquée `inscription: null` (aucune copie remise n'est
        // invisible). Un inactif sans copie, lui, n'apparaît pas.
        $partie = $this->eleve($ecole, $classe, 'Pia Partie', 'inactif');
        $evaluation = $this->evaluation($classe, $maths, $enseignant);
        $this->copie($evaluation, $partie, 'soumis', '19.00');

        $resultats = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class')
            ->assertOk()
            ->json('data.resultats');

        self::assertSame([
            $awa->getKey() => ['Awa Koné', 'non_passee', 'active'],
            $partie->getKey() => ['Pia Partie', 'soumis', null],
        ], $this->lignesEtInscription($resultats));
        self::assertNotContains($ailleurs->getKey(), array_column($resultats, 'etudiant_id'));
    }

    public function test_la_ligne_porte_la_derniere_copie_et_ignore_l_entrainement(): void
    {
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $awa = $this->eleve($ecole, $classe, 'Awa Koné');
        $evaluation = $this->evaluation($classe, $maths, $enseignant, ['allow_retake' => true, 'max_attempts' => 3]);
        $this->copie($evaluation, $awa, 'soumis', '9.00', ['attempt' => 1, 'created_at' => now()->subHours(3)]);
        $this->copie($evaluation, $awa, 'soumis', '12.00', ['attempt' => 2, 'created_at' => now()->subHours(2)]);
        $this->copie($evaluation, $awa, 'soumis', '20.00', [
            'attempt' => 3, 'created_at' => now()->subHour(), 'feedback' => '[PRACTICE] Mode entraînement',
        ]);

        $resultats = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class')
            ->assertOk()
            ->json('data.resultats');

        self::assertSame([$awa->getKey() => ['Awa Koné', '12.00', 'soumis']], $this->lignes($resultats));
    }

    public function test_les_statistiques_comptent_le_roster_actif(): void
    {
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $evaluation = $this->evaluation($classe, $maths, $enseignant);
        $this->copie($evaluation, $this->eleve($ecole, $classe, 'Awa Koné'), 'soumis', '15.00');
        $this->copie($evaluation, $this->eleve($ecole, $classe, 'Yao Kouassi'), 'corrige', '8.00');
        $this->copie($evaluation, $this->eleve($ecole, $classe, 'Ella Encours'), 'en_cours', null);
        $this->eleve($ecole, $classe, 'Noé Nonpasse');
        $this->eleve($ecole, $classe, 'Ina Ctif', 'inactif');

        $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class')
            ->assertOk()
            ->assertJsonPath('data.statistiques', [
                'total_etudiants' => 4,
                'etudiants_soumis' => 2,
                'etudiants_en_cours' => 1,
                // C28 : aucune copie hors délai ici.
                'etudiants_hors_delai' => 0,
                'etudiants_non_passes' => 1,
                // JSON rend `50.0` en `50` : un entier au décodage.
                'taux_participation' => 50,
                'moyenne_classe' => 11.5,
                'note_max' => 15,
                'note_min' => 8,
            ]);
    }

    public function test_le_roster_des_resultats_est_l_audience_de_l_eleve(): void
    {
        // Une seule règle des inscriptions actives : pour chaque statut
        // d'inscription, l'élève figure dans les résultats SI ET SEULEMENT SI
        // il voit l'évaluation (l'audience d'E2a).
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $evaluation = $this->evaluation($classe, $maths, $enseignant);
        $eleves = [];
        foreach (ClasseEtudiantStatut::cases() as $statut) {
            $eleves[$statut->value] = $this->eleve($ecole, $classe, 'Élève '.$statut->value, $statut->value);
        }

        $roster = array_column($this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class')
            ->assertOk()
            ->json('data.resultats'), 'etudiant_id');

        $tenants = app(TenantManager::class);
        $tenants->set($ecole);
        $audience = app(LearnerEvaluationScope::class);
        $voient = array_keys(array_filter($eleves, static fn (User $eleve): bool => $audience->includes($eleve, $evaluation)));
        $tenants->reset();

        self::assertSame([ClasseEtudiantStatut::Actif->value], $voient);
        self::assertSame([$eleves[ClasseEtudiantStatut::Actif->value]->getKey()], $roster);
    }

    public function test_un_eleve_admis_par_le_repli_qui_remet_apparait_dans_les_resultats(): void
    {
        // Règle : aucune copie remise n'est invisible pour l'enseignant. Awa
        // n'a aucune inscription active : l'audience l'admet par le repli
        // `user_classes` (C24). Elle démarre et remet par les VRAIES routes,
        // puis apparaît dans les résultats, marquée hors roster
        // (`inscription: null`) et jamais comptée parmi les absents.
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $classe->forceFill(['klassci_id' => 77])->save();
        $yao = $this->eleve($ecole, $classe, 'Yao Kouassi');
        $awa = $this->membre($ecole, 'etudiant');
        $awa->forceFill(['name' => 'Awa Koné'])->save();
        DB::table('user_classes')->insert([
            'user_id' => $awa->getKey(), 'klassci_classe_id' => 77, 'institution_id' => $ecole->getKey(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $evaluation = $this->evaluation($classe, $maths, $enseignant, ['date_evaluation' => now()->subMinutes(10)]);
        $question = EvaluationQuestion::query()->where('evaluation_id', $evaluation->getKey())->firstOrFail();
        $this->asTenant($awa)->postJson($this->de($evaluation).'/start')->assertSuccessful();
        $this->asTenant($awa)->postJson($this->de($evaluation).'/submit', ['answers' => [(string) $question->getKey() => 'a']])
            ->assertSuccessful();

        $reponse = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/results-by-class', 'get');
        $resultats = $reponse->assertOk()->json('data.resultats');

        self::assertSame([
            $yao->getKey() => ['Yao Kouassi', 'non_passee', 'active'],
            $awa->getKey() => ['Awa Koné', 'soumis', null],
        ], $this->lignesEtInscription($resultats));
        $reponse->assertJsonPath('data.statistiques.total_etudiants', 2)
            ->assertJsonPath('data.statistiques.etudiants_soumis', 1)
            ->assertJsonPath('data.statistiques.etudiants_non_passes', 1);
    }

    public function test_dans_une_ecole_klassci_une_copie_hors_du_roster_apparait_dans_les_resultats(): void
    {
        // Même règle : un élève sorti du roster KLASSCI après avoir remis
        // garde sa ligne, identifiée par son identité KLASSCI.
        [$enseignant, $evaluation] = $this->enseignantKlassci();
        $parti = $this->eleveKlassci($enseignant, 702, 'parti@ecole.test', 'Paul Parti');
        $this->copie($evaluation, $parti, 'soumis', '13.00');
        $this->rosterKlassci([['id' => 700, 'email' => 'awa@ecole.test', 'nom' => 'Koné', 'prenom' => 'Awa']]);

        $resultats = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class')
            ->assertOk()
            ->json('data.resultats');

        self::assertSame([
            700 => ['Koné Awa', 'non_passee', 'active'],
            702 => ['Paul Parti', 'soumis', null],
        ], $this->lignesEtInscription($resultats));
    }

    public function test_un_collegue_ne_lit_pas_les_resultats(): void
    {
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $evaluation = $this->evaluation($classe, $maths, $enseignant);

        $this->asTenant($this->membre($ecole, 'enseignant'))
            ->getJson($this->de($evaluation).'/results-by-class')
            ->assertForbidden();
    }

    // ───────────────────────── résultats, école créée depuis KLASSCI

    public function test_dans_une_ecole_klassci_la_copie_se_rattache_a_son_auteur_puis_a_l_identite_klassci(): void
    {
        // Le roster vient de KLASSCI, inchangé. La copie d'Awa porte son
        // auteur (`student_id`) et une identité KLASSCI périmée : elle se
        // rattache par l'auteur. Celle de Yao, d'avant D72, n'a que l'identité
        // KLASSCI : elle se rattache par elle, comme avant.
        [$enseignant, $evaluation] = $this->enseignantKlassci();
        $awa = $this->eleveKlassci($enseignant, 700, 'awa@ecole.test');
        $this->copie($evaluation, $awa, 'soumis', '14.00', ['klassci_etudiant_id' => 999]);
        $this->copie($evaluation, null, 'corrige', '11.00', ['klassci_etudiant_id' => 701, 'student_id' => null]);
        $this->rosterKlassci([
            ['id' => 700, 'email' => 'awa@ecole.test', 'nom' => 'Koné', 'prenom' => 'Awa'],
            ['id' => 701, 'email' => 'yao@ecole.test', 'nom' => 'Kouassi', 'prenom' => 'Yao'],
        ]);

        $resultats = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class')
            ->assertOk()
            ->json('data.resultats');

        self::assertSame([
            700 => ['Koné Awa', '14.00', 'soumis'],
            701 => ['Kouassi Yao', '11.00', 'corrige'],
        ], $this->lignes($resultats));
    }

    public function test_une_copie_qui_a_un_auteur_ne_va_pas_a_un_autre_par_l_identite_klassci_ecole_du_lms(): void
    {
        // La règle de `ownedBy` (revue croisée d'E2a) : la branche KLASSCI ne
        // vaut que pour une ligne SANS auteur. La copie de Yao porte
        // l'identité KLASSCI d'Awa (école basculée, rattrapage mal apparié) :
        // elle reste à Yao, et Awa n'a rien passé.
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $awa = $this->eleve($ecole, $classe, 'Awa Koné');
        $awa->forceFill(['klassci_id' => 501])->save();
        $yao = $this->eleve($ecole, $classe, 'Yao Kouassi');
        $evaluation = $this->evaluation($classe, $maths, $enseignant);
        $this->copie($evaluation, $yao, 'soumis', '12.00', ['klassci_etudiant_id' => 501]);

        $resultats = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class')
            ->assertOk()
            ->json('data.resultats');

        self::assertSame([
            $awa->getKey() => ['Awa Koné', null, 'non_passee'],
            $yao->getKey() => ['Yao Kouassi', '12.00', 'soumis'],
        ], $this->lignes($resultats));
    }

    public function test_une_copie_qui_a_un_auteur_ne_va_pas_a_un_autre_par_l_identite_klassci_ecole_klassci(): void
    {
        [$enseignant, $evaluation] = $this->enseignantKlassci();
        $this->eleveKlassci($enseignant, 700, 'awa@ecole.test', 'Awa Koné');
        $yao = $this->eleveKlassci($enseignant, 701, 'yao@ecole.test', 'Yao Kouassi');
        $this->copie($evaluation, $yao, 'soumis', '12.00', ['klassci_etudiant_id' => 700]);
        $this->rosterKlassci([
            ['id' => 700, 'email' => 'awa@ecole.test', 'nom' => 'Koné', 'prenom' => 'Awa'],
            ['id' => 701, 'email' => 'yao@ecole.test', 'nom' => 'Kouassi', 'prenom' => 'Yao'],
        ]);

        $resultats = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class')
            ->assertOk()
            ->json('data.resultats');

        self::assertSame([
            700 => ['Koné Awa', null, 'non_passee'],
            701 => ['Kouassi Yao', '12.00', 'soumis'],
        ], $this->lignes($resultats));
    }

    public function test_dans_une_ecole_klassci_le_compte_est_retrouve_par_l_identite_quand_l_email_differe(): void
    {
        // Revue d'E3 : KLASSCI n'a pas le même email que le compte local. Sans
        // compte retrouvé, la copie d'Awa (qui a un auteur) ne se rattachait
        // plus, et Awa avait deux lignes : « non passée » au roster, « soumis »
        // hors roster. Le compte est retrouvé par son identité KLASSCI.
        [$enseignant, $evaluation] = $this->enseignantKlassci();
        $awa = $this->eleveKlassci($enseignant, 700, 'awa@ecole.test', 'Awa Koné');
        $this->copie($evaluation, $awa, 'soumis', '14.00');
        $this->rosterKlassci([['id' => 700, 'email' => 'a.kone@klassci.test', 'nom' => 'Koné', 'prenom' => 'Awa']]);

        $resultats = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class')
            ->assertOk()
            ->json('data.resultats');

        self::assertSame([700 => ['Koné Awa', 'soumis', 'active']], $this->lignesEtInscription($resultats));
    }

    public function test_sans_etablissement_la_source_locale_ne_trouve_aucune_evaluation(): void
    {
        // Revue d'E3 : `where('institution_id', null)` deviendrait `IS NULL` et
        // viserait les évaluations sans établissement. Un compte sans
        // établissement ne trouve rien.
        $sansEcole = $this->membre($this->ecole(), 'enseignant');
        $sansEcole->forceFill(['institution_id' => null])->save();
        $orpheline = Evaluation::factory()->create(['institution_id' => null, 'enseignant_id' => $sansEcole->getKey()]);
        app(TenantManager::class)->reset();

        $reponse = app(LocalEvaluationResultsReader::class)->getResultsByClass((int) $orpheline->getKey(), $sansEcole);

        self::assertSame(404, $reponse['status']);
    }

    public function test_la_meme_route_sert_une_ecole_klassci_puis_une_ecole_du_lms(): void
    {
        // ADR-EI-01, règle 3 : le contrôleur est mémoïsé sur l'objet Route.
        // Une source reçue par son constructeur servirait la première école à
        // toutes les suivantes.
        [$enseignantKlassci, $deKlassci] = $this->enseignantKlassci();
        $this->rosterKlassci([['id' => 700, 'email' => 'awa@ecole.test', 'nom' => 'Koné', 'prenom' => 'Awa']]);
        [$ecole, $enseignantLms, $classe, $maths] = $this->enseignantEtSaClasse();
        $yao = $this->eleve($ecole, $classe, 'Yao Kouassi');
        $deLms = $this->evaluation($classe, $maths, $enseignantLms);

        $route = fn (User $qui, Evaluation $evaluation): array => array_column(
            $this->asTenant($qui)->getJson($this->de($evaluation).'/results-by-class')->assertOk()->json('data.resultats'),
            'etudiant_id',
        );

        self::assertSame([700], $route($enseignantKlassci, $deKlassci));
        self::assertSame([$yao->getKey()], $route($enseignantLms, $deLms));
        self::assertSame([700], $route($enseignantKlassci, $deKlassci));
    }

    // ───────────────────────── la liste des copies

    public function test_chaque_copie_locale_porte_le_nom_de_son_eleve(): void
    {
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $awa = $this->eleve($ecole, $classe, 'Awa Koné');
        $yao = $this->eleve($ecole, $classe, 'Yao Kouassi');
        $evaluation = $this->evaluation($classe, $maths, $enseignant);
        $this->copie($evaluation, $awa, 'soumis', '15.00');
        $this->copie($evaluation, $yao, 'soumis', '8.00');
        $this->aucunAppelKlassci();

        $reponse = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/submissions');
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/submissions', 'get');
        $copies = $reponse->assertOk()->json('data');

        self::assertEqualsCanonicalizing(
            [[$awa->getKey(), 'Awa Koné'], [$yao->getKey(), 'Yao Kouassi']],
            array_map(static fn (array $c): array => [$c['student_id'], $c['student_name']], $copies),
        );
    }

    public function test_dans_une_ecole_klassci_la_copie_est_nommee_par_son_auteur_puis_par_klassci(): void
    {
        [$enseignant, $evaluation] = $this->enseignantKlassci();
        $awa = $this->eleveKlassci($enseignant, 700, 'awa@ecole.test', 'Awa Koné');
        $this->eleveKlassci($enseignant, 701, 'yao@ecole.test', 'Yao Kouassi');
        $this->copie($evaluation, $awa, 'soumis', '14.00', ['klassci_etudiant_id' => 999]);
        $this->copie($evaluation, null, 'soumis', '11.00', ['klassci_etudiant_id' => 701, 'student_id' => null]);
        // Une identité KLASSCI portée par un compte d'une AUTRE école ne
        // nomme pas la copie : le nom resterait celui d'un tiers.
        $ailleurs = $this->membre($this->ecole(InstitutionMode::Klassci), 'etudiant');
        $ailleurs->forceFill(['klassci_id' => 702, 'name' => 'Compte Tiers'])->save();
        $this->copie($evaluation, null, 'soumis', '10.00', ['klassci_etudiant_id' => 702, 'student_id' => null]);

        $copies = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/submissions')
            ->assertOk()
            ->json('data');

        self::assertEqualsCanonicalizing(
            [[$awa->getKey(), 'Awa Koné'], [null, 'Yao Kouassi'], [null, 'Étudiant #702']],
            array_map(static fn (array $c): array => [$c['student_id'], $c['student_name']], $copies),
        );
    }

    public function test_les_noms_sont_bornes_a_l_etablissement_meme_sans_tenant(): void
    {
        // Le scope global de `User` borne la requête HTTP au tenant : il cache
        // le bornage explicite. Sans tenant (une tâche de fond), il s'ouvre ;
        // seul le `where` de `NomsDesAuteurs` empêche alors de nommer la copie
        // du nom d'un compte d'une autre école qui porte la même identité.
        [, $evaluation] = $this->enseignantKlassci();
        $ailleurs = $this->membre($this->ecole(InstitutionMode::Klassci), 'etudiant');
        $ailleurs->forceFill(['klassci_id' => 702, 'name' => 'Compte Tiers'])->save();
        $copie = $this->copie($evaluation, null, 'soumis', '10.00', ['klassci_etudiant_id' => 702, 'student_id' => null]);
        app(TenantManager::class)->reset();

        $nom = NomsDesAuteurs::de(collect([$copie]), $evaluation->institution_id);

        self::assertSame('Étudiant #702', $nom($copie));
    }

    // ───────────────────────── noter et prévisualiser

    public function test_l_enseignant_note_une_dissertation_et_un_collegue_recoit_403(): void
    {
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $awa = $this->eleve($ecole, $classe, 'Awa Koné');
        $evaluation = $this->evaluation($classe, $maths, $enseignant);
        $dissertation = EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->getKey(), 'type' => 'dissertation', 'points' => 10,
        ]);
        $copie = $this->copie($evaluation, $awa, 'soumis', '0.00', [
            'answers' => [(string) $dissertation->getKey() => 'Une dissertation'],
        ]);
        $note = fn (User $qui) => $this->asTenant($qui)->postJson(
            $this->de($evaluation).'/submissions/'.$copie->getKey().'/grade',
            ['points' => [(string) $dissertation->getKey() => 8]],
        );
        $this->aucunAppelKlassci();

        $note($this->membre($ecole, 'enseignant'))->assertForbidden();
        self::assertSame('soumis', $copie->fresh()?->status);

        $note($enseignant)->assertOk()->assertJsonPath('data.status', 'corrige');
        self::assertSame('corrige', $copie->fresh()?->status);
    }

    public function test_la_previsualisation_marche_sans_klassci_et_sans_corrige(): void
    {
        [, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $evaluation = $this->evaluation($classe, $maths, $enseignant);
        $this->aucunAppelKlassci();

        $questions = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/preview')
            ->assertOk()
            ->assertJsonPath('data.is_preview', true)
            ->assertJsonPath('data.questions_count', 1)
            ->json('data.questions');

        self::assertIsArray($questions);
        self::assertArrayNotHasKey('correct_answers', $questions[0]);
    }

    // ───────────────────────── synchroniser vers KLASSCI

    public function test_une_evaluation_sans_lien_klassci_refuse_la_synchronisation_par_un_motif(): void
    {
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $evaluation = $this->evaluation($classe, $maths, $enseignant);
        $copie = $this->copie($evaluation, $this->eleve($ecole, $classe, 'Awa Koné'), 'soumis', '15.00');
        $this->aucunAppelKlassci();

        foreach (['/sync-klassci', '/sync-notes'] as $route) {
            $reponse = $this->asTenant($enseignant)->postJson($this->de($evaluation).$route)
                ->assertStatus(422)
                ->assertJsonPath('reason', 'evaluation_not_linked_to_klassci');
            $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}'.$route, 'post');
        }

        self::assertFalse((bool) $copie->fresh()?->synced_to_klassci);
        self::assertFalse((bool) $evaluation->fresh()?->notes_published);
    }

    // ───────────────────────── C28 : la copie hors délai

    /**
     * L'enseignant VOIT la copie verrouillée, qu'elle ait été refusée à la remise
     * (statut écrit) ou jamais remise après son échéance (statut dérivé de
     * `ends_at`), mais ne la note pas : l'utilisateur l'a dite « refusée ».
     */
    public function test_c28_l_enseignant_voit_la_copie_hors_delai_et_ne_la_note_pas_ecole_du_lms(): void
    {
        [$ecole, $enseignant, $classe, $maths] = $this->enseignantEtSaClasse();
        $evaluation = $this->evaluation($classe, $maths, $enseignant);
        $dissertation = EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->getKey(), 'type' => 'dissertation', 'points' => 10,
        ]);
        $awa = $this->eleve($ecole, $classe, 'Awa Refusée');
        $yao = $this->eleve($ecole, $classe, 'Yao Échu');
        $refusee = $this->copie($evaluation, $awa, 'verrouille', null, ['ends_at' => now()->subMinutes(5)]);
        $echue = $this->copie($evaluation, $yao, 'en_cours', null, ['ends_at' => now()->subMinutes(5)]);
        $this->aucunAppelKlassci();

        $this->assertHorsDelaiVisibleEtNonNotee($enseignant, $evaluation, [$refusee, $echue], $dissertation);
    }

    public function test_c28_l_enseignant_voit_la_copie_hors_delai_et_ne_la_note_pas_ecole_klassci(): void
    {
        [$enseignant, $evaluation] = $this->enseignantKlassci();
        $dissertation = EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->getKey(), 'type' => 'dissertation', 'points' => 10,
        ]);
        $awa = $this->eleveKlassci($enseignant, 701, 'awa@ecole.test', 'Awa Refusée');
        $yao = $this->eleveKlassci($enseignant, 702, 'yao@ecole.test', 'Yao Échu');
        $this->rosterKlassci([
            ['id' => 701, 'nom' => 'Refusée', 'prenom' => 'Awa', 'email' => 'awa@ecole.test'],
            ['id' => 702, 'nom' => 'Échu', 'prenom' => 'Yao', 'email' => 'yao@ecole.test'],
        ]);
        $refusee = $this->copie($evaluation, $awa, 'verrouille', null, ['ends_at' => now()->subMinutes(5)]);
        $echue = $this->copie($evaluation, $yao, 'en_cours', null, ['ends_at' => now()->subMinutes(5)]);

        $this->assertHorsDelaiVisibleEtNonNotee($enseignant, $evaluation, [$refusee, $echue], $dissertation);
    }

    /** @param  list<EvaluationSubmission>  $copies */
    private function assertHorsDelaiVisibleEtNonNotee(User $enseignant, Evaluation $evaluation, array $copies, EvaluationQuestion $dissertation): void
    {
        $resultats = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/results-by-class');
        $this->assertMatchesOpenApiResponse($resultats, '/evaluations/{id}/results-by-class', 'get');
        $resultats->assertOk()
            ->assertJsonPath('data.statistiques.etudiants_hors_delai', 2)
            ->assertJsonPath('data.statistiques.etudiants_en_cours', 0);
        $statuts = array_column((array) $resultats->json('data.resultats'), 'status');
        self::assertSame(['verrouille', 'verrouille'], $statuts);

        $liste = $this->asTenant($enseignant)->getJson($this->de($evaluation).'/submissions');
        $this->assertMatchesOpenApiResponse($liste, '/evaluations/{id}/submissions', 'get');
        self::assertSame(['verrouille', 'verrouille'], array_column((array) $liste->assertOk()->json('data'), 'status'));

        foreach ($copies as $copie) {
            $note = $this->asTenant($enseignant)->postJson(
                $this->de($evaluation).'/submissions/'.$copie->getKey().'/grade',
                ['points' => [(string) $dissertation->getKey() => 8]],
            );
            $this->assertMatchesOpenApiResponse($note, '/evaluations/{id}/submissions/{submission}/grade', 'post');
            $note->assertStatus(409)->assertJsonPath('reason', 'evaluation_attempt_locked');
            self::assertNull($copie->fresh()?->note_sur_20);
        }
    }

    // ───────────────────────── harnais

    /**
     * @return array{Institution, User, Classe, Matiere}
     */
    private function enseignantEtSaClasse(): array
    {
        $ecole = $this->ecole();

        return [$ecole, $this->membre($ecole, 'enseignant'), $this->classe($ecole, '6e A'), $this->matiere($ecole, 'Maths')];
    }

    private function eleve(Institution $ecole, Classe $classe, string $nom, string $statut = 'actif'): User
    {
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['name' => $nom])->save();
        $this->inscrire($eleve, $classe, $statut);

        return $eleve;
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function evaluation(Classe $classe, Matiere $matiere, User $auteur, array $surcharges = []): Evaluation
    {
        // Comme `LocalEvaluationProgramming` l'écrit : ids et libellés locaux,
        // aucune identité KLASSCI.
        $evaluation = Evaluation::factory()->create(array_merge([
            'institution_id' => $classe->institution_id,
            'classe_id' => $classe->getKey(),
            'classe_nom' => $classe->libelle,
            'matiere_id' => $matiere->getKey(),
            'matiere_nom' => $matiere->libelle,
            'enseignant_id' => $auteur->getKey(),
            'klassci_evaluation_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'klassci_enseignant_id' => null,
            'is_published' => true,
            // Fixés : la fabrique les tire au hasard. Hors ligne, `/start`
            // refuse ; et la synchronisation vérifie que `notes_published`
            // reste faux.
            'is_online' => true,
            'allow_retake' => false,
            'max_attempts' => 1,
            'show_results' => true,
            'notes_published' => false,
            'status' => 'planifiee',
            'date_evaluation' => now()->subDay(),
            'duree_minutes' => 60,
        ], $surcharges));
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->getKey(), 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a'],
        ]);

        return $evaluation;
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function copie(Evaluation $evaluation, ?User $eleve, string $statut, ?string $note, array $surcharges = []): EvaluationSubmission
    {
        return EvaluationSubmission::factory()->create(array_merge([
            'evaluation_id' => $evaluation->getKey(),
            'institution_id' => $evaluation->institution_id,
            'student_id' => $eleve?->getKey(),
            'klassci_etudiant_id' => $eleve?->klassci_id,
            'attempt' => 1,
            'status' => $statut,
            'answers' => null,
            'feedback' => null,
            'score' => $note,
            'note_sur_20' => $note,
            'submitted_at' => $statut === 'en_cours' ? null : now(),
            'synced_to_klassci' => false,
        ], $surcharges));
    }

    /**
     * @return array{User, Evaluation}
     */
    private function enseignantKlassci(): array
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $enseignant = $this->membre($ecole, 'enseignant');
        $enseignant->forceFill(['klassci_token' => 'jeton', 'klassci_enseignant_id' => 42])->save();
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_classe_id' => 55,
            'klassci_enseignant_id' => 42,
            'enseignant_id' => $enseignant->getKey(),
            'is_published' => true,
        ]);
        $this->disableKlassciMiddleware();

        return [$enseignant, $evaluation];
    }

    private function eleveKlassci(User $enseignant, int $klassciId, string $email, string $nom = 'Élève'): User
    {
        $eleve = User::factory()->create([
            'institution_id' => $enseignant->institution_id,
            'role' => 'etudiant',
            'klassci_id' => $klassciId,
            'klassci_token' => null,
            'email' => $email,
            'name' => $nom,
        ]);

        return $eleve;
    }

    /**
     * Le roster livré dans l'enveloppe `classes/{id}`, comme KLASSCI le fait
     * (#669, patron d'EvaluationResultsCorrectnessTest).
     *
     * @param  list<array<string, mixed>>  $roster
     */
    private function rosterKlassci(array $roster): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($roster): void {
            $mock->shouldReceive('requestWithUserToken')->andReturn([
                'data' => ['classe' => ['id' => 55, 'nom' => 'B2 COM'], 'etudiants' => $roster],
            ]);
            $mock->shouldReceive('getClasses')->andReturn(['data' => []]);
            $mock->shouldReceive('getMatieres')->andReturn(['data' => []]);
        });
    }

    /**
     * Aucun appel à KLASSCI : ni requête sortante, ni méthode du mandataire.
     * Les attentes `never` sont vérifiées à la fin du test, même si l'appel a
     * été avalé par un repli.
     */
    private function aucunAppelKlassci(): void
    {
        Http::preventStrayRequests();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getClasses', 'getMatieres', 'requestWithUserToken', 'saveNotes');
        });
    }

    /**
     * @param  mixed  $resultats
     * @return array<int|string, array{mixed, mixed, mixed}>
     */
    private function lignes(mixed $resultats): array
    {
        self::assertIsArray($resultats);
        $lignes = [];
        foreach ($resultats as $ligne) {
            self::assertIsArray($ligne);
            // Une ligne par élève : un doublon (roster ET hors roster) ne doit
            // pas s'écraser en silence.
            self::assertArrayNotHasKey($ligne['etudiant_id'], $lignes, 'deux lignes pour le même élève');
            $lignes[$ligne['etudiant_id']] = [$ligne['etudiant_nom_complet'], $ligne['note'], $ligne['status']];
        }
        ksort($lignes);

        return $lignes;
    }

    /**
     * @return array<int|string, array{mixed, mixed, mixed}>
     */
    private function lignesEtInscription(mixed $resultats): array
    {
        self::assertIsArray($resultats);
        $lignes = [];
        foreach ($resultats as $ligne) {
            self::assertIsArray($ligne);
            self::assertArrayNotHasKey($ligne['etudiant_id'], $lignes, 'deux lignes pour le même élève');
            $lignes[$ligne['etudiant_id']] = [$ligne['etudiant_nom_complet'], $ligne['status'], $ligne['inscription']];
        }
        ksort($lignes);

        return $lignes;
    }

    private function de(Evaluation $evaluation): string
    {
        return '/api/evaluations/'.$evaluation->getKey();
    }
}
