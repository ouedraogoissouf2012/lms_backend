<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Exceptions\KlassciUnavailableException;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\OpensEvaluationAttempt;
use Tests\TestCase;

/**
 * Ce qu'un élève atteint d'une évaluation suit UNE règle, celle de sa liste
 * (`/evaluations/student`) : {@see \App\Services\Evaluation\Student\LearnerEvaluationScope}.
 * - Démarrer une tentative et lire son état temporel : seulement ce que sa
 *   liste propose, sinon 404 sans distinction.
 * - Rendre la copie : la tentative ouverte suffit, sans relire KLASSCI.
 * - Lire SA copie : la propriété de la copie, dans son établissement, quelle
 *   que soit sa classe actuelle (l'écran des notes propose aussi les copies
 *   des classes passées).
 * - La fiche matière ne lui montre que les évaluations de sa liste.
 * - La recherche ne lui rend aucune évaluation.
 *
 * L'état de PRODUCTION est reproduit : l'élève est connecté par KLASSCI, sa
 * classe vient de la source vive `me/dashboard`, sans ligne `user_classes`
 * (#969). KLASSCI est simulé par UNE closure, qui répond selon le point
 * d'accès et note chaque appel. Vrai jeton porteur (`asTenant`), sauf là où
 * l'absence de tenant est précisément l'objet du test.
 */
final class EvaluationLearnerReachTest extends TestCase
{
    use ActsAsTenantUser;
    use OpensEvaluationAttempt;
    use RefreshDatabase;

    private const CLASSE = 4242;

    private const AUTRE_CLASSE = 4343;

    private const MATIERE = 9100;

    private const KLASSCI_ID = 910001;

    private Institution $ecole;

    /** @var list<string> Points d'accès KLASSCI appelés, dans l'ordre. */
    private array $appelsKlassci = [];

    // ── Démarrer ─────────────────────────────────────────────────────────────

    /**
     * Le critère d'invalidation du lot, sur UNE fixture : ce que la liste
     * propose se démarre, puis se rend. Si la règle refusait plus que la liste,
     * l'élève verrait une carte qu'il ne peut pas passer.
     */
    public function test_ce_que_la_liste_propose_se_demarre_puis_se_rend(): void
    {
        $eleve = $this->eleveConnecte();
        $this->klassciRend(self::CLASSE);
        $evaluation = $this->evaluation(self::CLASSE);

        $liste = $this->asTenant($eleve)->getJson('/api/evaluations/student')->assertOk();
        self::assertContains($evaluation->id, array_column((array) $liste->json('data'), 'id'));

        $this->asTenant($eleve)->postJson("/api/evaluations/{$evaluation->id}/start")->assertOk();
        $this->asTenant($eleve)->postJson("/api/evaluations/{$evaluation->id}/submit", $this->reponses($evaluation))
            ->assertCreated();
        self::assertSame(1, $this->copies());
    }

    /**
     * Hors de la liste de l'élève, quelle qu'en soit la raison : 404, le même
     * corps qu'une évaluation absente, et AUCUNE copie écrite.
     *
     * @return iterable<string, array{0: ?int, 1: array<string, mixed>, 2: bool}>
     */
    public static function horsDeSaListe(): iterable
    {
        yield 'une autre classe de son école' => [self::AUTRE_CLASSE, ['status' => 'planifiee'], true];
        yield 'un brouillon publié de sa classe' => [self::CLASSE, ['status' => 'brouillon', 'is_published' => true], true];
        yield 'une évaluation non publiée de sa classe' => [self::CLASSE, ['status' => 'planifiee', 'is_published' => false], true];
        yield 'une évaluation sans question de sa classe' => [self::CLASSE, ['status' => 'planifiee'], false];
        yield 'une évaluation sans classe' => [null, ['status' => 'planifiee'], true];
    }

    /** @param array<string, mixed> $attributs */
    #[DataProvider('horsDeSaListe')]
    public function test_hors_de_sa_liste_rien_ne_se_demarre(?int $classe, array $attributs, bool $avecQuestion): void
    {
        $eleve = $this->eleveConnecte();
        $this->klassciRend(self::CLASSE);
        $evaluation = $this->evaluation($classe, $attributs, $avecQuestion);

        $refus = $this->asTenant($eleve)->postJson("/api/evaluations/{$evaluation->id}/start");
        $absente = $this->asTenant($eleve)->postJson('/api/evaluations/999999/start');

        $refus->assertNotFound();
        self::assertSame($absente->json(), $refus->json(), 'le refus se distingue d une evaluation absente');
        self::assertSame(0, $this->copies(), 'une copie a ete ecrite hors de la liste de l eleve');
    }

    /**
     * Une autre école, au MÊME identifiant de classe. Sans tenant résolu, à
     * dessein (`Sanctum::actingAs` n'émet aucun jeton) : le scope global est
     * alors ouvert, et seul le filtre explicite de la règle tient.
     */
    public function test_une_autre_ecole_au_meme_identifiant_de_classe_ne_se_demarre_pas(): void
    {
        $eleve = $this->eleveConnecte();
        $ailleurs = $this->evaluationDansUneAutreEcole(self::CLASSE);
        $this->klassciRend(self::CLASSE);
        Sanctum::actingAs($eleve);

        $this->postJson("/api/evaluations/{$ailleurs->id}/start")->assertNotFound();
        self::assertSame(0, $this->copies());
    }

    /** La même autre école, par la vraie route et un vrai jeton : le corps du refus est celui d'une évaluation absente. */
    public function test_une_autre_ecole_par_un_vrai_jeton_repond_comme_une_evaluation_absente(): void
    {
        $eleve = $this->eleveConnecte();
        $ailleurs = $this->evaluationDansUneAutreEcole(self::CLASSE);
        $this->klassciRend(self::CLASSE);

        $refus = $this->asTenant($eleve)->postJson("/api/evaluations/{$ailleurs->id}/start");
        $absente = $this->asTenant($eleve)->postJson('/api/evaluations/999999/start');

        $refus->assertNotFound();
        self::assertSame($absente->json(), $refus->json());
        self::assertSame(0, $this->copies());
    }

    /**
     * Un élève sans classe chez KLASSCI (`me/dashboard` ne la donne pas) n'atteint
     * rien : ni démarrage, ni état temporel, ni évaluation sur la fiche matière.
     */
    public function test_sans_classe_l_eleve_n_atteint_aucune_evaluation(): void
    {
        $eleve = $this->eleveConnecte();
        $this->ficheMatiere(classeDeLEleve: null);
        $sienne = Evaluation::withoutGlobalScopes()->where('klassci_evaluation_id', 5001)->firstOrFail();

        $this->asTenant($eleve)->postJson("/api/evaluations/{$sienne->id}/start")->assertNotFound();
        $this->asTenant($eleve)->getJson("/api/evaluations/{$sienne->id}/time-status")->assertNotFound();
        $evaluations = (array) $this->asTenant($eleve)->getJson('/api/lms/matieres/' . self::MATIERE)->assertOk()->json('data.evaluations_programmees');

        self::assertSame(0, $this->copies());
        self::assertSame([], array_values(array_filter(array_column($evaluations, 'lms_id'))), 'une evaluation LMS est montree a un eleve sans classe');
        self::assertSame([false, false], array_column($evaluations, 'has_online'));
    }

    /** Jeton KLASSCI expiré en lisant la classe : 401 comme la liste, jamais 500, aucune copie. */
    public function test_un_jeton_klassci_expire_au_demarrage_rend_401_sans_copie(): void
    {
        $eleve = $this->eleveConnecte();
        $evaluation = $this->evaluation(self::CLASSE);
        $this->klassciLeve(new \RuntimeException('Erreur API KLASSCI: 401', 401));

        $demarrage = $this->asTenant($eleve)->postJson("/api/evaluations/{$evaluation->id}/start");
        $liste = $this->asTenant($eleve)->getJson('/api/evaluations/student');

        self::assertSame(401, $demarrage->status());
        self::assertSame($liste->json(), $demarrage->json(), 'meme reponse que la liste de l eleve');
        self::assertSame(0, $this->copies());
    }

    /** KLASSCI injoignable : la classe n'est pas vérifiable, refus explicite (503), aucune copie. */
    public function test_klassci_injoignable_au_demarrage_rend_503_sans_copie(): void
    {
        $eleve = $this->eleveConnecte();
        $evaluation = $this->evaluation(self::CLASSE);
        $this->klassciLeve(KlassciUnavailableException::circuitOpen(30));

        self::assertSame(503, $this->asTenant($eleve)->postJson("/api/evaluations/{$evaluation->id}/start")->status());
        self::assertSame(0, $this->copies());
    }

    /**
     * Volet école autonome. Un élève d'école autonome n'a ni jeton ni identité
     * KLASSCI, et ses évaluations sont locales (sans classe KLASSCI) : il n'a
     * aujourd'hui aucun écran d'évaluation (#805). Au-delà du 401, rien n'est
     * atteignable. La classe se lit chez KLASSCI, et la propriété d'une copie
     * porte l'identité KLASSCI. Les trois routes refusent donc en 401, sans
     * rien écrire.
     */
    public function test_dans_une_ecole_autonome_rien_ne_se_demarre(): void
    {
        $eleve = $this->eleveConnecte(ecoleAutonome: true);
        $this->klassciRend(self::CLASSE);
        $locale = $this->evaluation(null);

        $this->asTenant($eleve)->postJson("/api/evaluations/{$locale->id}/start")->assertUnauthorized();
        $this->asTenant($eleve)->getJson("/api/evaluations/{$locale->id}/time-status")->assertUnauthorized();
        $this->asTenant($eleve)->getJson("/api/evaluations/{$locale->id}/my-submission")->assertUnauthorized();
        self::assertSame(0, $this->copies());
    }

    // ── Rendre ───────────────────────────────────────────────────────────────

    /**
     * Rendre la copie ne relit PAS la classe chez KLASSCI. La remise ne clôt
     * qu'une tentative que le démarrage a admise. Un appel ici rendrait la
     * remise d'examen dépendante de KLASSCI au moment même où l'élève rend : le
     * front ne la retente pas.
     */
    public function test_rendre_sa_copie_ne_relit_pas_sa_classe(): void
    {
        $eleve = $this->eleveConnecte();
        $this->klassciRend(self::CLASSE);
        $evaluation = $this->evaluation(self::CLASSE);
        $this->ouvrirTentative($evaluation, $eleve);

        $this->asTenant($eleve)->postJson("/api/evaluations/{$evaluation->id}/submit", $this->reponses($evaluation))
            ->assertCreated();

        self::assertNotContains('me/dashboard', $this->appelsKlassci, 'la remise a relu la classe chez KLASSCI');
    }

    /**
     * Un homonyme (même identifiant KLASSCI) d'un élève qui a une copie : sans
     * établissement, ou d'une autre école. Sans tenant résolu, à dessein : le
     * scope global est ouvert, et seule la portée de propriété tient.
     *
     * @return iterable<string, array{0: bool}>
     */
    public static function homonymes(): iterable
    {
        yield 'sans établissement' => [true];
        yield 'd une autre école' => [false];
    }

    /** Sa remise n'écrit RIEN dans la tentative ouverte de l'homonyme (vérifié en base). */
    #[DataProvider('homonymes')]
    public function test_la_remise_d_un_homonyme_n_ecrit_pas_dans_la_copie_d_autrui(bool $sansEtablissement): void
    {
        $victime = $this->eleveConnecte();
        $evaluation = $this->evaluation(self::CLASSE);
        $tentative = $this->ouvrirTentative($evaluation, $victime);
        $this->klassciRend(self::CLASSE);
        Sanctum::actingAs($this->homonyme($sansEtablissement));

        $this->postJson("/api/evaluations/{$evaluation->id}/submit", $this->reponses($evaluation))->assertStatus(409);

        $copie = EvaluationSubmission::withoutGlobalScopes()->findOrFail($tentative->id);
        self::assertSame('en_cours', $copie->status, 'la copie de l homonyme a ete rendue');
        self::assertNull($copie->submitted_at);
        self::assertSame(1, $this->copies());
    }

    /** Il ne reçoit sur `/my-grades` aucune copie notée de l'homonyme. */
    #[DataProvider('homonymes')]
    public function test_les_notes_d_un_homonyme_ne_contiennent_aucune_copie_d_autrui(bool $sansEtablissement): void
    {
        $victime = $this->eleveConnecte();
        $this->copieRendue($this->evaluation(self::CLASSE, ['status' => 'terminee']), $victime, now()->subDays(8));
        $this->klassciRend(self::CLASSE);
        Sanctum::actingAs($this->homonyme($sansEtablissement));

        $notes = $this->getJson('/api/my-grades')->assertOk();

        self::assertSame(0, $notes->json('data.total_evaluations'), 'des copies d un autre eleve sont rendues');
        self::assertStringNotContainsString('Algebre, controle', (string) $notes->getContent());
    }

    // ── État temporel ────────────────────────────────────────────────────────

    /** KLASSCI injoignable en lisant la classe : l'état temporel rend 503, rien de l'évaluation. */
    public function test_klassci_injoignable_sur_l_etat_temporel_rend_503(): void
    {
        $eleve = $this->eleveConnecte();
        $evaluation = $this->evaluation(self::CLASSE);
        $this->klassciLeve(KlassciUnavailableException::circuitOpen(30));

        $reponse = $this->asTenant($eleve)->getJson("/api/evaluations/{$evaluation->id}/time-status");

        self::assertSame(503, $reponse->status());
        $reponse->assertJsonMissingPath('data');
    }

    public function test_l_etat_temporel_suit_la_meme_regle(): void
    {
        $eleve = $this->eleveConnecte();
        $this->klassciRend(self::CLASSE);
        $sienne = $this->evaluation(self::CLASSE);
        $autre = $this->evaluation(self::AUTRE_CLASSE);

        $this->asTenant($eleve)->getJson("/api/evaluations/{$sienne->id}/time-status")->assertOk();
        $refus = $this->asTenant($eleve)->getJson("/api/evaluations/{$autre->id}/time-status");
        $absente = $this->asTenant($eleve)->getJson('/api/evaluations/999999/time-status');

        $refus->assertNotFound()->assertJsonMissingPath('data');
        self::assertSame($absente->json(), $refus->json());
    }

    // ── Lire sa copie ────────────────────────────────────────────────────────

    /**
     * L'élève passé dans une autre classe lit toujours SA copie, et le corrigé
     * suit la même règle qu'avant (#883) : visible après le délai, masqué avant.
     */
    public function test_sa_copie_d_une_classe_passee_reste_lisible_avec_le_corrige_selon_le_delai(): void
    {
        $eleve = $this->eleveConnecte();
        $this->klassciRend(self::CLASSE);
        $ancienne = $this->evaluation(self::AUTRE_CLASSE, ['status' => 'terminee']);
        $recente = $this->evaluation(self::AUTRE_CLASSE, ['status' => 'terminee']);
        $this->copieRendue($ancienne, $eleve, now()->subDays(8));
        $this->copieRendue($recente, $eleve, now()->subDays(2));

        $apresDelai = $this->asTenant($eleve)->getJson("/api/evaluations/{$ancienne->id}/my-submission")->assertOk();
        $avantDelai = $this->asTenant($eleve)->getJson("/api/evaluations/{$recente->id}/my-submission")->assertOk();

        $apresDelai->assertJsonPath('data.correction_available', true);
        self::assertStringContainsString('LA-REPONSE-SECRETE', (string) $apresDelai->getContent());
        $avantDelai->assertJsonPath('data.correction_available', false);
        self::assertStringNotContainsString('LA-REPONSE-SECRETE', (string) $avantDelai->getContent());
    }

    public function test_la_copie_d_un_camarade_reste_introuvable(): void
    {
        $eleve = $this->eleveConnecte();
        $this->klassciRend(self::CLASSE);
        $evaluation = $this->evaluation(self::CLASSE);
        $camarade = User::factory()->create([
            'institution_id' => $this->ecole->id, 'role' => 'etudiant', 'klassci_id' => self::KLASSCI_ID + 1,
        ]);
        $this->copieRendue($evaluation, $camarade, now()->subDays(8));

        $refus = $this->asTenant($eleve)->getJson("/api/evaluations/{$evaluation->id}/my-submission");
        $absente = $this->asTenant($eleve)->getJson('/api/evaluations/999999/my-submission');

        $refus->assertNotFound();
        self::assertSame($absente->json(), $refus->json());
    }

    /**
     * `ownedBy` ne porte que l'identifiant KLASSCI, unique au sein d'un seul
     * tenant KLASSCI. Une copie d'une AUTRE école, au même identifiant, ne se
     * lit dans aucun des trois cas, et le refus a le corps d'une copie absente.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function memeIdentifiantKlassciAilleurs(): iterable
    {
        yield 'autre école, vrai jeton (tenant résolu)' => ['autre-ecole-jeton'];
        yield 'autre école, aucun tenant résolu' => ['autre-ecole-sans-tenant'];
        yield 'aucun établissement, aucun tenant résolu' => ['sans-etablissement'];
    }

    #[DataProvider('memeIdentifiantKlassciAilleurs')]
    public function test_la_copie_d_une_autre_ecole_au_meme_identifiant_reste_introuvable(string $cas): void
    {
        $victime = $this->eleveConnecte();
        $copie = $this->evaluation(self::CLASSE);
        $this->copieRendue($copie, $victime, now()->subDays(8));
        $this->klassciRend(self::CLASSE);

        $intrus = User::factory()->create([
            'institution_id' => $cas === 'sans-etablissement' ? null : Institution::factory()->create()->id,
            'role' => 'etudiant',
            'klassci_id' => self::KLASSCI_ID,
            'klassci_token' => 'jeton-intrus',
        ]);
        $appel = function (string $url) use ($cas, $intrus): TestResponse {
            if ($cas === 'autre-ecole-jeton') {
                return $this->asTenant($intrus)->getJson($url);
            }
            Sanctum::actingAs($intrus);

            return $this->getJson($url);
        };

        $refus = $appel("/api/evaluations/{$copie->id}/my-submission");
        $absente = $appel('/api/evaluations/999999/my-submission');

        $refus->assertNotFound();
        self::assertSame($absente->json(), $refus->json(), 'le refus se distingue d une copie absente');
        self::assertStringNotContainsString('LA-REPONSE-SECRETE', (string) $refus->getContent());
    }

    /**
     * Sans établissement, le filtre ne devient pas `institution_id IS NULL` : un
     * compte sans établissement ne lit pas une copie ORPHELINE (sans
     * établissement, sur une évaluation sans établissement) qui porte son
     * identifiant KLASSCI. C'est le rôle du `1 = 0` (ADR-792-01).
     */
    public function test_sans_etablissement_une_copie_orpheline_reste_introuvable(): void
    {
        $this->disableKlassciMiddleware();
        $orpheline = Evaluation::factory()->create([
            'institution_id' => null, 'klassci_classe_id' => self::CLASSE, 'status' => 'terminee', 'is_published' => true,
        ]);
        EvaluationSubmission::create([
            'evaluation_id' => $orpheline->id, 'klassci_etudiant_id' => self::KLASSCI_ID, 'attempt' => 1,
            'status' => 'soumis', 'submitted_at' => now()->subDays(8), 'institution_id' => null,
        ]);
        $sansEtablissement = User::factory()->create([
            'institution_id' => null, 'role' => 'etudiant', 'klassci_id' => self::KLASSCI_ID, 'klassci_token' => 'jeton',
        ]);
        $this->klassciRend(self::CLASSE);
        Sanctum::actingAs($sansEtablissement);

        $refus = $this->getJson("/api/evaluations/{$orpheline->id}/my-submission");

        $refus->assertNotFound();
        self::assertSame($this->getJson('/api/evaluations/999999/my-submission')->json(), $refus->json());
    }

    /**
     * Donnée INCOHÉRENTE, à dessein : une copie rangée dans l'école de l'élève,
     * mais sur une évaluation d'une AUTRE école. La propriété de la copie ne
     * suffit pas : l'évaluation est cherchée, elle aussi, dans l'établissement
     * de l'élève. C'est le seul cas qui distingue ce second filtre.
     */
    public function test_une_copie_sur_une_evaluation_d_une_autre_ecole_reste_introuvable(): void
    {
        $eleve = $this->eleveConnecte();
        $ailleurs = $this->evaluationDansUneAutreEcole(self::CLASSE);
        $copie = $this->copieRendue($ailleurs, $eleve, now()->subDays(8));
        $copie->forceFill(['institution_id' => $eleve->institution_id])->save();
        $this->klassciRend(self::CLASSE);
        Sanctum::actingAs($eleve);

        $refus = $this->getJson("/api/evaluations/{$ailleurs->id}/my-submission");

        $refus->assertNotFound();
        self::assertStringNotContainsString('LA-REPONSE-SECRETE', (string) $refus->getContent());
    }

    // ── Fiche matière ────────────────────────────────────────────────────────

    /**
     * La fiche matière, par la route réelle, ne montre à l'élève que les
     * évaluations LMS de sa liste. Une version en ligne qu'il ne peut pas
     * passer compte comme absente : la fiche ne mène plus à un 404.
     */
    public function test_la_fiche_matiere_ne_montre_a_l_eleve_que_les_evaluations_de_sa_liste(): void
    {
        $eleve = $this->eleveConnecte();
        $fiche = $this->ficheMatiere(self::CLASSE);

        $evaluations = $this->asTenant($eleve)->getJson('/api/lms/matieres/' . self::MATIERE)->assertOk()->json('data.evaluations_programmees');
        $parId = array_column((array) $evaluations, null, 'id');

        self::assertTrue($parId[5001]['has_online'], 'la version en ligne de sa classe a disparu');
        self::assertSame($fiche['versionSienne']->id, $parId[5001]['online_version']['id']);
        self::assertFalse($parId[5002]['has_online'], 'une version d une autre classe mene a l ecran de passage');
        self::assertNull($parId[5002]['online_version']);
        self::assertSame([$fiche['lmsSienne']->id], array_values(array_filter(array_column((array) $evaluations, 'lms_id'))));
    }

    /** Le personnel garde sa vue de la fiche, inchangée. */
    public function test_l_enseignant_garde_toute_la_fiche_matiere(): void
    {
        $enseignant = $this->eleveConnecte('enseignant');
        $fiche = $this->ficheMatiere(self::CLASSE);

        $evaluations = (array) $this->asTenant($enseignant)->getJson('/api/lms/matieres/' . self::MATIERE)->assertOk()->json('data.evaluations_programmees');
        $parId = array_column($evaluations, null, 'id');

        self::assertTrue($parId[5001]['has_online']);
        self::assertTrue($parId[5002]['has_online']);
        $lms = array_values(array_filter(array_column($evaluations, 'lms_id')));
        sort($lms);
        self::assertSame([$fiche['lmsSienne']->id, $fiche['lmsBrouillon']->id, $fiche['lmsAutreClasse']->id], $lms);
    }

    // ── Recherche ────────────────────────────────────────────────────────────

    /**
     * La recherche ne rend aucune évaluation à un élève, même un titre exact
     * d'une évaluation de SA classe, d'un statut réel : le résultat mène à un
     * écran enseignant, où l'élève n'a pas de destination.
     */
    public function test_la_recherche_ne_rend_aucune_evaluation_a_l_eleve(): void
    {
        $eleve = $this->eleveConnecte();
        $this->klassciRend(self::CLASSE);
        $this->evaluation(self::CLASSE, ['status' => 'planifiee']);

        $resultats = $this->asTenant($eleve)->getJson('/api/search?query=algebre&limit=20')->assertOk()->json('results');
        $suggestions = $this->asTenant($eleve)->getJson('/api/search/suggestions?query=algebre')->assertOk()->json('suggestions');

        self::assertSame([], $resultats['evaluations']);
        self::assertNotContains('Algebre, controle', (array) $suggestions);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * Compte connecté par KLASSCI, comme en production : jeton présent, AUCUNE
     * ligne `user_classes`, aucun pivot.
     */
    private function eleveConnecte(string $role = 'etudiant', bool $ecoleAutonome = false): User
    {
        $this->disableKlassciMiddleware();
        $this->ecole = $ecoleAutonome ? Institution::factory()->standalone()->create() : Institution::factory()->create();

        return User::factory()->create([
            'institution_id' => $this->ecole->id,
            'role' => $role,
            'klassci_id' => $ecoleAutonome ? null : self::KLASSCI_ID,
            'klassci_token' => $ecoleAutonome ? null : 'jeton-eleve',
        ]);
    }

    /** @param array<string, mixed> $attributs */
    private function evaluation(?int $klassciClasseId, array $attributs = [], bool $avecQuestion = true): Evaluation
    {
        $evaluation = Evaluation::factory()->create($attributs + [
            'institution_id' => $this->ecole->id,
            'klassci_classe_id' => $klassciClasseId,
            'klassci_matiere_id' => self::MATIERE,
            'titre' => 'Algebre, controle',
            'status' => 'planifiee',
            'is_published' => true,
            'is_online' => true,
            'max_attempts' => 3,
            'allow_retake' => true,
            'show_results' => true,
        ]);
        if ($avecQuestion) {
            EvaluationQuestion::factory()->create([
                'evaluation_id' => $evaluation->id,
                'institution_id' => $this->ecole->id,
                'type' => 'qcm',
                'correct_answers' => ['LA-REPONSE-SECRETE'],
                'points' => 20,
            ]);
        }

        return $evaluation;
    }

    private function evaluationDansUneAutreEcole(int $klassciClasseId): Evaluation
    {
        $ecoleDeLEleve = $this->ecole;
        $this->ecole = Institution::factory()->create();
        $ailleurs = $this->evaluation($klassciClasseId);
        $this->ecole = $ecoleDeLEleve;

        return $ailleurs;
    }

    /** Un élève, sans établissement ou d'une autre école, qui porte le MÊME identifiant KLASSCI que l'élève des fixtures. */
    private function homonyme(bool $sansEtablissement): User
    {
        return User::factory()->create([
            'institution_id' => $sansEtablissement ? null : Institution::factory()->create()->id,
            'role' => 'etudiant',
            'klassci_id' => self::KLASSCI_ID,
            'klassci_token' => 'jeton-homonyme',
        ]);
    }

    private function copieRendue(Evaluation $evaluation, User $auteur, \DateTimeInterface $remise): EvaluationSubmission
    {
        $copie = $this->ouvrirTentative($evaluation, $auteur);
        $copie->forceFill(['status' => 'soumis', 'submitted_at' => $remise, 'answers' => []])->save();

        return $copie;
    }

    /**
     * La fiche de la matière : deux évaluations KLASSCI embarquées (5001 dans
     * la classe de l'élève, 5002 dans une autre), chacune avec sa version en
     * ligne, et trois évaluations LMS seules (de sa classe, un brouillon de sa
     * classe, d'une autre classe).
     *
     * @return array{versionSienne: Evaluation, lmsSienne: Evaluation, lmsBrouillon: Evaluation, lmsAutreClasse: Evaluation}
     */
    private function ficheMatiere(?int $classeDeLEleve): array
    {
        $fiche = [
            'versionSienne' => $this->evaluation(self::CLASSE, ['klassci_evaluation_id' => 5001]),
            'lmsSienne' => $this->evaluation(self::CLASSE, ['klassci_evaluation_id' => null]),
            'lmsBrouillon' => $this->evaluation(self::CLASSE, ['klassci_evaluation_id' => null, 'status' => 'brouillon', 'is_published' => false]),
            'lmsAutreClasse' => $this->evaluation(self::AUTRE_CLASSE, ['klassci_evaluation_id' => null]),
        ];
        $this->evaluation(self::AUTRE_CLASSE, ['klassci_evaluation_id' => 5002]);
        $embarquee = static fn (int $id, int $classe): array => [
            'id' => $id, 'titre' => 'Devoir', 'type' => 'devoir', 'status' => 'planned', 'classe' => ['id' => $classe],
        ];
        $this->klassciRend($classeDeLEleve, [
            'matieres/' . self::MATIERE => ['data' => [
                'matiere' => ['id' => self::MATIERE, 'nom' => 'Algebre'],
                'combinaisons' => [],
                'enseignants' => [['id' => 1, 'nom' => 'Prof']],
                'evaluations' => [$embarquee(5001, self::CLASSE), $embarquee(5002, self::AUTRE_CLASSE)],
            ]],
        ]);

        return $fiche;
    }

    /**
     * KLASSCI : la classe de l'élève (ou aucune) sur `me/dashboard`, les
     * réponses données pour d'autres points d'accès, et rien ailleurs (aucune
     * fenêtre). Chaque appel est noté.
     *
     * @param  array<string, array<string, mixed>>  $autres
     */
    private function klassciRend(?int $classe, array $autres = []): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($classe, $autres): void {
            // Les noms de matières de l'écran des notes : aucun, sans effet sur les copies rendues.
            $mock->shouldReceive('getMatieres')->andReturn(['success' => true, 'data' => []]);
            $mock->shouldReceive('requestWithUserToken')
                ->andReturnUsing(function (string $jeton, string $endpoint) use ($classe, $autres): array {
                    $this->appelsKlassci[] = $endpoint;
                    if ($endpoint === 'me/dashboard') {
                        return ['data' => $classe === null ? [] : ['classe' => ['id' => $classe, 'name' => 'L1 Informatique']]];
                    }

                    return $autres[$endpoint] ?? ['data' => []];
                });
        });
    }

    private function klassciLeve(\Throwable $erreur): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($erreur): void {
            $mock->shouldReceive('requestWithUserToken')->andThrow($erreur);
        });
    }

    /** @return array<string, mixed> */
    private function reponses(Evaluation $evaluation): array
    {
        $question = $evaluation->questions()->firstOrFail();

        return ['answers' => [$question->id => 'A']];
    }

    private function copies(): int
    {
        return EvaluationSubmission::withoutGlobalScopes()->count();
    }
}
