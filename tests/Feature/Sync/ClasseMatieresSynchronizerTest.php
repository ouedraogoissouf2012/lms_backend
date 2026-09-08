<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Services\MatiereSyncService;
use App\Services\Sync\Classes\ClasseMatieresSynchronizer;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Le miroir local des matières d'une classe (#740).
 *
 * ## Le défaut, mesuré de bout en bout
 *
 * `ClasseSyncService::syncClasseById()` reçoit de KLASSCI un payload
 * `classes/{id}` qui contient `classe` ET `matieres`. Il n'en gardait que la
 * première clé — `$klasseData = $responseData['classe'] ?? $responseData` — et
 * **jetait les matières**.
 *
 * Conséquence : le LIEN classe ↔ matière n'existe nulle part, et une matière
 * sans séance n'a donc aucune classe à proposer à l'enseignant.
 *
 * ⚠️ Une version antérieure de ce docblock affirmait que la table `matieres`
 * « reste vide pour toujours, aucun autre écrivain n'existe », en citant
 * `grep -rn "Matiere::create|updateOrCreate" app/ database/`. **La preuve était
 * invalide et la conclusion fausse** : `MatiereSyncService` alimente cette table
 * depuis #258, à chaque connexion enseignant, mais via `new Matiere()` +
 * `save()` — une forme que ce grep ne peut pas voir. La prémisse a produit un
 * bug de perte de données, verrouillé depuis par
 * `test_it_never_erases_what_the_complete_source_wrote`.
 *
 * Le vrai défaut du 422 était une divergence d'espace d'identifiants, traitée
 * par `App\Rules\MirroredIdentifierExists`.
 *
 * ## Pourquoi un miroir, et pas un assouplissement de la règle
 *
 * `lessons.matiere_id` est un identifiant LOCAL. Y ranger un id KLASSCI serait
 * mélanger deux espaces d'identifiants dans une même colonne — précisément la
 * faute qui a produit la fuite entre enseignants de #707. La traduction
 * KLASSCI → local existe déjà dans `LessonCrudOperationsService` ; il lui
 * manquait seulement une ligne locale vers laquelle traduire.
 */
#[CoversClass(ClasseMatieresSynchronizer::class)]
final class ClasseMatieresSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Classe $classe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);

        $this->classe = Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /**
     * LE défaut corrigé : les matières du payload sont désormais miroitées.
     */
    public function test_the_matieres_of_the_payload_are_mirrored_locally(): void
    {
        $this->sync([
            ['id' => 3, 'nom' => 'Anglais', 'code' => 'ID5356'],
            ['id' => 1, 'nom' => 'Marketing digital', 'code' => 'MKT1'],
        ]);

        self::assertSame(2, Matiere::query()->count());

        $anglais = Matiere::query()->where('klassci_id', 3)->firstOrFail();
        self::assertSame('Anglais', $anglais->libelle);
        self::assertSame('ID5356', $anglais->code);
        self::assertSame($this->institution->id, $anglais->institution_id);
    }

    /**
     * La relation classe ↔ matière est ce qui permettra plus tard de proposer
     * une classe à l'enseignant depuis une matière — y compris une matière sans
     * aucune séance, cas qui bloquait toute création de leçon.
     */
    public function test_the_classe_matiere_link_is_recorded(): void
    {
        $this->sync([['id' => 3, 'nom' => 'Anglais']]);

        $matiere = Matiere::query()->where('klassci_id', 3)->firstOrFail();

        self::assertSame(1, DB::table('classe_matiere')
            ->where('classe_id', $this->classe->id)
            ->where('matiere_id', $matiere->id)
            ->where('institution_id', $this->institution->id)
            ->count());
    }

    /**
     * Rejouer la synchronisation ne doit ni dupliquer la matière, ni dupliquer
     * le lien : le cycle tourne toutes les cinq minutes.
     */
    public function test_syncing_twice_duplicates_nothing(): void
    {
        $payload = [['id' => 3, 'nom' => 'Anglais']];

        $this->sync($payload);
        $this->sync($payload);

        self::assertSame(1, Matiere::query()->count());
        self::assertSame(1, DB::table('classe_matiere')->count());
    }

    /**
     * Un libellé mis à jour côté KLASSCI se propage, sans créer de doublon.
     */
    public function test_a_renamed_matiere_is_updated_in_place(): void
    {
        $this->sync([['id' => 3, 'nom' => 'Anglais']]);
        $this->sync([['id' => 3, 'nom' => 'Anglais renforcé']]);

        self::assertSame(1, Matiere::query()->count());
        self::assertSame('Anglais renforcé', Matiere::query()->where('klassci_id', 3)->value('libelle'));
    }

    /**
     * Une entrée sans identifiant exploitable est écartée sans faire échouer le
     * lot : une matière malformée ne doit pas priver les autres de leur miroir.
     */
    public function test_a_malformed_entry_does_not_abort_the_batch(): void
    {
        $this->sync([
            ['nom' => 'Sans identifiant'],
            ['id' => null, 'nom' => 'Identifiant nul'],
            ['id' => 3, 'nom' => 'Anglais'],
        ]);

        self::assertSame(1, Matiere::query()->count());
        self::assertSame(3, Matiere::query()->value('klassci_id'));
    }

    /**
     * L'isolation multi-tenant : le `klassci_id` n'est unique QUE par
     * institution. Deux établissements peuvent légitimement porter la matière 3,
     * et elles ne doivent jamais fusionner.
     */
    public function test_the_same_klassci_id_stays_isolated_per_institution(): void
    {
        $this->sync([['id' => 3, 'nom' => 'Anglais']]);

        $autre = Institution::factory()->create();
        app(TenantManager::class)->set($autre);
        $autreClasse = Classe::factory()->create(['institution_id' => $autre->id, 'klassci_id' => 1]);

        app(ClasseMatieresSynchronizer::class)->sync($autreClasse, [['id' => 3, 'nom' => 'English']]);

        self::assertSame(2, Matiere::withoutGlobalScope('institution')->where('klassci_id', 3)->count());
    }

    /**
     * LE défaut trouvé en revue adversariale : ce service n'est PAS le seul
     * écrivain de `matieres`.
     *
     * {@see MatiereSyncService} l'alimente depuis #258, à chaque
     * connexion enseignant, en lisant l'endpoint COMPLET `matieres` : code,
     * description, coefficient, crédit, filière, niveau, semestre, et le payload
     * intégral dans `klassci_data`.
     *
     * Le bloc `matieres` de `classes/{id}` est, lui, un RÉSUMÉ — il ne porte ni
     * code ni description. Écrire ces colonnes inconditionnellement les remettait
     * donc à `null` sur une fiche complète, quelques minutes après son écriture,
     * et la perte se rejouait à chaque nouvelle séance créée.
     *
     * Deux écrivains sur une table, c'est deux vérités concurrentes : le défaut
     * de classe exact que le reste de ce lot supprime sur les identifiants.
     */
    public function test_it_never_erases_what_the_complete_source_wrote(): void
    {
        $complete = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 3,
            'libelle' => 'Anglais',
            'code' => 'ANG101',
            'description' => "Cours d'anglais des affaires",
            'klassci_data' => ['id' => 3, 'code' => 'ANG101', 'coefficient' => 4],
        ]);

        // Le résumé de `classes/{id}` : ni code, ni description.
        $this->sync([['id' => 3, 'nom' => 'Anglais']]);

        $complete->refresh();
        self::assertSame('ANG101', $complete->code, 'le code de la fiche complète a été écrasé');
        self::assertSame("Cours d'anglais des affaires", $complete->description, 'la description a été écrasée');
        self::assertSame(4, $complete->klassci_data['coefficient'] ?? null, 'le payload complet a été remplacé par le résumé');
    }

    /**
     * `last_klassci_sync` gouverne `Matiere::isKlassciDataFresh()` : elle affirme
     * « la fiche COMPLÈTE a été rafraîchie ». Un miroir partiel qui l'horodate
     * ferait passer pour fraîche une fiche qu'il vient d'appauvrir.
     *
     * Aucun appelant ne lit `Matiere::isKlassciDataFresh()` aujourd'hui — c'est
     * donc un mensonge latent, pas une panne active. On ne le laisse pas
     * s'installer pour autant.
     */
    public function test_a_partial_mirror_never_claims_the_record_is_fresh(): void
    {
        $matiere = Matiere::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 3,
            'last_klassci_sync' => null,
        ]);

        $this->sync([['id' => 3, 'nom' => 'Anglais']]);

        self::assertNull($matiere->refresh()->last_klassci_sync);
    }

    /**
     * @param  list<array<string, mixed>>  $matieres
     */
    private function sync(array $matieres): void
    {
        app(ClasseMatieresSynchronizer::class)->sync($this->classe, $matieres);
    }
}
