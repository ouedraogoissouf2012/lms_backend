<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Sync\Classes\ClasseMatieresSynchronizer;
use App\Services\TenantManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #801 (PR 1 bis) — une ligne de `classe_etudiant` ou de `classe_matiere` appartient à l'école
 * de sa CLASSE, par construction.
 *
 * ## Le défaut
 *
 * Un pivot ne passe pas par `BelongsToInstitution` : `LocalClasseMatiereLinker::rattacher()`
 * (`syncWithoutDetaching` sans `institution_id`) écrivait `classe_matiere` SANS école, même
 * avec un tenant résolu, à chaque rattachement matière-classe d'une école autonome. Mesuré :
 * passer la colonne en NOT NULL faisait échouer 502 écritures par `attach`/`sync`.
 *
 * ## La règle
 *
 * La classe pivot dérive l'école de la classe quand elle n'est pas fournie ; une école
 * FOURNIE qui contredit celle de la classe est REFUSÉE ; une classe sans école ne dérive
 * rien (aucun repli sur le tenant). Depuis la classe, sans requête de plus.
 */
final class LaLignePivotPorteLEcoleDeSaClasseTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_une_ecole_autonome_rattache_une_matiere_avec_son_ecole(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        $gestionnaire = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin']);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);
        $matiere = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);

        $this->asTenant($gestionnaire)->postJson("/api/classes/{$classe->id}/matieres", ['matiere_id' => $matiere->id])
            ->assertSuccessful();

        self::assertSame($ecole->id, $this->ecoleDe('classe_matiere', 'matiere_id', $classe, $matiere->id));
    }

    public function test_un_eleve_attache_depuis_la_classe_prend_son_ecole(): void
    {
        $classe = Classe::factory()->create();
        $eleve = User::factory()->create(['institution_id' => $classe->institution_id]);

        $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);

        self::assertSame($classe->institution_id, $this->ecoleDe('classe_etudiant', 'user_id', $classe, $eleve->id));
    }

    public function test_une_classe_attachee_depuis_le_compte_prend_son_ecole(): void
    {
        $classe = Classe::factory()->create();
        $eleve = User::factory()->create(['institution_id' => $classe->institution_id]);

        $eleve->classes()->attach($classe->id, ['statut' => 'actif']);

        self::assertSame($classe->institution_id, $this->ecoleDe('classe_etudiant', 'user_id', $classe, $eleve->id));
    }

    public function test_une_classe_attachee_depuis_la_matiere_prend_son_ecole(): void
    {
        $classe = Classe::factory()->create();
        $matiere = Matiere::factory()->create(['institution_id' => $classe->institution_id]);

        $matiere->classes()->attach($classe->id);

        self::assertSame($classe->institution_id, $this->ecoleDe('classe_matiere', 'matiere_id', $classe, $matiere->id));
    }

    public function test_une_ecole_fournie_qui_contredit_la_classe_est_refusee(): void
    {
        $classe = Classe::factory()->create();
        $eleve = User::factory()->create(['institution_id' => $classe->institution_id]);
        $autre = Institution::factory()->create();

        try {
            $classe->etudiants()->attach($eleve->id, ['institution_id' => $autre->id, 'statut' => 'actif']);
            self::fail('Une école qui contredit la classe devait être refusée.');
        } catch (LogicException) {
            self::assertSame(0, DB::table('classe_etudiant')->where('classe_id', $classe->id)->count(), 'Rien n\'est écrit.');
        }
    }

    public function test_une_mise_a_jour_qui_contredit_la_classe_est_refusee(): void
    {
        $classe = Classe::factory()->create();
        $eleve = User::factory()->create(['institution_id' => $classe->institution_id]);
        $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);

        $this->expectException(LogicException::class);

        $classe->etudiants()->syncWithoutDetaching([$eleve->id => ['institution_id' => Institution::factory()->create()->id]]);
    }

    /**
     * Aucun repli sur le tenant : une classe sans école ne prête pas celle du tenant.
     *
     * #801 (PR 2) : une classe sans école ne peut plus exister en BASE. Elle est donc éprouvée EN
     * MÉMOIRE : l'école se lit sur la classe chargée (`pivotParent`), dont on efface l'école sans
     * l'enregistrer. Sans repli, la ligne reste sans école et la base la refuse ; un repli sur le
     * tenant l'aurait écrite avec l'école du tenant.
     */
    public function test_une_classe_sans_ecole_ne_prend_pas_celle_du_tenant(): void
    {
        $tenant = Institution::factory()->create();
        $classe = Classe::factory()->create(['institution_id' => $tenant->id]);
        $matiere = Matiere::factory()->create(['institution_id' => $tenant->id]);
        $this->app->make(TenantManager::class)->set($tenant);
        $classe->institution_id = null;

        try {
            $classe->matieres()->attach($matiere->id);
            self::fail('Sans école dérivée, la base devait refuser la ligne.');
        } catch (QueryException) {
            self::assertNull($this->ecoleDe('classe_matiere', 'matiere_id', $classe, $matiere->id));
        }
    }

    /**
     * Depuis la classe, l'école se lit sur la classe déjà chargée : AUCUNE lecture de `classes`,
     * quel que soit le nombre d'élèves. Comparé sur les SELECT normalisés, horloge figée.
     */
    public function test_attacher_n_eleves_depuis_la_classe_ne_relit_jamais_la_classe(): void
    {
        Carbon::setTestNow('2026-11-23 10:00:00');
        $classe = Classe::factory()->create();
        $eleves = User::factory()->count(5)->create(['institution_id' => $classe->institution_id]);

        $lectures = [];
        DB::listen(static function (QueryExecuted $requete) use (&$lectures): void {
            if (preg_match('/^\s*select\b.*\bfrom\s+["`]?classes["`]?/i', $requete->sql) === 1) {
                $lectures[] = $requete->sql;
            }
        });

        $classe->etudiants()->attach($eleves->pluck('id')->all(), ['statut' => 'actif']);

        self::assertSame([], $lectures, 'La classe est déjà là : elle ne se relit pas.');
        self::assertSame(5, DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('institution_id', $classe->institution_id)->count());
    }

    /** Témoin du volet KLASSCI : la synchronisation classe-matière (#1380) pose toujours l'école. */
    public function test_la_synchronisation_klassci_pose_toujours_l_ecole(): void
    {
        $classe = Classe::factory()->create(['klassci_id' => 31]);

        $this->app->make(ClasseMatieresSynchronizer::class)->sync($classe, [['id' => 77, 'libelle' => 'Physique']]);

        $matiere = Matiere::query()->withoutGlobalScope('institution')->where('klassci_id', 77)->sole();
        self::assertSame($classe->institution_id, $this->ecoleDe('classe_matiere', 'matiere_id', $classe, $matiere->id));
    }

    private function ecoleDe(string $pivot, string $autreCle, Classe $classe, int $autreId): ?int
    {
        $ecole = DB::table($pivot)->where('classe_id', $classe->id)->where($autreCle, $autreId)->value('institution_id');

        return is_numeric($ecole) ? (int) $ecole : null;
    }
}
