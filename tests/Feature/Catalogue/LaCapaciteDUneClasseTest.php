<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\PreparesClasseWithCode;
use Tests\TestCase;

/**
 * #1358 (volet back) — une école autonome règle la capacité d'une classe (`effectif`).
 *
 * Depuis #1115, `effectif` est la capacité que l'inscription par code respecte, et 0 veut dire
 * « non renseignée, sans limite ». Une école autonome ne pouvait la poser nulle part : vide refusé
 * à la création, aucune modification, et la liste `GET /classes` ne la rendait pas.
 *
 * - Création : `effectif` facultatif, nul compris (sans limite).
 * - Modification : le geste NOMMÉ `PUT /classes/{classe}/capacite`, corps exigé, nul compris. Baisser
 *   la capacité n'expulse personne : elle ne ferme que les nouvelles entrées.
 * - Lecture : `GET /classes` rend la capacité, ou `null` si elle n'est pas renseignée.
 * - École KLASSCI : la capacité vient de KLASSCI ; la porte du catalogue refuse (403).
 *
 * Vrai jeton (`asTenant`), les deux volets.
 */
final class LaCapaciteDUneClasseTest extends TestCase
{
    use ActsAsTenantUser;
    use PreparesClasseWithCode;
    use RefreshDatabase;

    // ── Création ──

    public function test_la_capacite_se_pose_a_la_creation(): void
    {
        $ecole = Institution::factory()->standalone()->create();

        $id = $this->asTenant($this->responsable($ecole))
            ->postJson('/api/classes', ['libelle' => 'BTS 1', 'effectif' => 30])
            ->assertCreated()->json('data.id');

        self::assertSame(30, $this->capaciteEnBase($id));
    }

    public function test_une_capacite_vide_a_la_creation_est_sans_limite(): void
    {
        $ecole = Institution::factory()->standalone()->create();

        $id = $this->asTenant($this->responsable($ecole))
            ->postJson('/api/classes', ['libelle' => 'BTS 1', 'effectif' => null])
            ->assertCreated()->json('data.id');

        self::assertSame(0, $this->capaciteEnBase($id));
    }

    // ── Modification ──

    public function test_la_capacite_se_modifie(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $classe = $this->classeDe($ecole, 30);

        $this->modifier($this->responsable($ecole), $classe, ['effectif' => 25])
            ->assertOk()
            ->assertJsonPath('data.id', $classe->id)
            ->assertJsonPath('data.effectif', 25);

        self::assertSame(25, $this->capaciteEnBase($classe->id));
    }

    /** @return iterable<string, array{0: int|null}> */
    public static function capacitesVides(): iterable
    {
        yield 'null' => [null];
        yield 'zéro' => [0];
    }

    #[DataProvider('capacitesVides')]
    public function test_vider_la_capacite_la_rend_sans_limite(?int $vide): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $classe = $this->classeDe($ecole, 30);

        $this->modifier($this->responsable($ecole), $classe, ['effectif' => $vide])
            ->assertOk()
            ->assertJsonPath('data.effectif', null);

        self::assertSame(0, $this->capaciteEnBase($classe->id));
    }

    /** Un corps vide ne dit pas si l'on veut vider : il est refusé, rien n'est effacé. */
    public function test_un_corps_sans_capacite_est_refuse(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $classe = $this->classeDe($ecole, 30);

        $this->modifier($this->responsable($ecole), $classe, [])
            ->assertStatus(422)->assertJsonValidationErrors('effectif');

        self::assertSame(30, $this->capaciteEnBase($classe->id));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function capacitesInvalides(): iterable
    {
        yield 'négative' => [-1];
        yield 'trop grande' => [10001];
        yield 'texte' => ['trente'];
    }

    #[DataProvider('capacitesInvalides')]
    public function test_une_capacite_invalide_est_refusee(mixed $valeur): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $classe = $this->classeDe($ecole, 30);

        $this->modifier($this->responsable($ecole), $classe, ['effectif' => $valeur])
            ->assertStatus(422)->assertJsonValidationErrors('effectif');

        self::assertSame(30, $this->capaciteEnBase($classe->id));
    }

    /** Baisser sous l'effectif actuel n'expulse personne : seules les nouvelles entrées sont fermées. */
    public function test_baisser_la_capacite_ne_retire_personne(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $classe = $this->classeDe($ecole, 30);
        foreach (['a', 'b', 'c'] as $nom) {
            $eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant', 'email' => "{$nom}@test.ci"]);
            DB::table('classe_etudiant')->insert(['classe_id' => $classe->id, 'user_id' => $eleve->id, 'institution_id' => $ecole->id,
                'statut' => 'actif', 'date_inscription' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->modifier($this->responsable($ecole), $classe, ['effectif' => 1])->assertOk();

        self::assertSame(3, DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('statut', 'actif')->count());
    }

    public function test_une_classe_d_une_autre_ecole_est_introuvable(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $ailleurs = $this->classeDe(Institution::factory()->standalone()->create(), 30);

        // Le message distingue le refus de l'école d'une route absente, qui rendrait aussi un 404.
        $this->modifier($this->responsable($ecole), $ailleurs, ['effectif' => 5])
            ->assertNotFound()
            ->assertJsonPath('message', 'Classe introuvable dans cet établissement.');

        self::assertSame(30, $this->capaciteEnBase($ailleurs->id));
    }

    /** École KLASSCI : la capacité vient de KLASSCI, la porte du catalogue refuse le geste. */
    public function test_dans_une_ecole_klassci_la_capacite_n_est_pas_modifiable(): void
    {
        $ecole = Institution::factory()->create();
        $miroir = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 55, 'effectif' => 40]);

        $this->modifier($this->responsable($ecole), $miroir, ['effectif' => 5])
            ->assertForbidden()
            ->assertJsonPath('reason', 'catalogue_managed_by_klassci');

        self::assertSame(40, $this->capaciteEnBase($miroir->id));
    }

    // ── Lecture ──

    public function test_la_liste_des_classes_rend_la_capacite_ou_null(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $bornee = $this->classeDe($ecole, 30, 'A bornée');
        $libre = $this->classeDe($ecole, 0, 'B libre');

        $lignes = (array) $this->asTenant($this->responsable($ecole))->getJson('/api/classes')->assertOk()->json('data');

        self::assertSame([$bornee->id => 30, $libre->id => null], array_column($lignes, 'effectif', 'id'));
    }

    // ── Témoin #1115 ──

    /** La capacité posée par le geste ferme bien la porte par code. */
    public function test_une_capacite_posee_ferme_la_porte_par_code(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $this->asTenant($this->apprenant($ecole, 'premier@test.ci'))->postJson('/api/me/inscriptions', ['code' => $code])->assertStatus(201);

        $this->modifier($this->responsable($ecole), $classe, ['effectif' => 1])->assertOk();

        $this->asTenant($this->apprenant($ecole, 'second@test.ci'))->postJson('/api/me/inscriptions', ['code' => $code])
            ->assertStatus(409)->assertJsonPath('reason', 'class_full');
    }

    // ── Harnais ──

    /** @param  array<string, mixed>  $charge */
    private function modifier(User $responsable, Classe $classe, array $charge): TestResponse
    {
        return $this->asTenant($responsable)->putJson("/api/classes/{$classe->id}/capacite", $charge);
    }

    private function classeDe(Institution $ecole, int $capacite, string $libelle = 'Classe'): Classe
    {
        return Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null, 'effectif' => $capacite, 'libelle' => $libelle]);
    }

    private function responsable(Institution $ecole): User
    {
        // Sans jeton KLASSCI : la re-synchronisation n'a rien à faire ici.
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null]);
    }

    private function capaciteEnBase(mixed $classeId): int
    {
        return (int) DB::table('classes')->where('id', $classeId)->value('effectif');
    }
}
