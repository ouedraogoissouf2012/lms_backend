<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #942 — un compte sans établissement ne lit AUCUNE leçon, sauf le supradmin
 * plateforme (`isPlatformSupradmin()`, comparaison stricte, #511), cross-tenant
 * par conception.
 *
 * Pour un tel compte, `ResolveInstitution` ne pose aucun tenant (#972) et le
 * scope global est ouvert par défaut : seul le filtre de LessonReadScope tient.
 * C'est le `null` absorbant d'ADR-792-01.
 *
 * Deux établissements, un par volet (klassci et standalone) : le compte n'en
 * voit aucun. Vrai jeton porteur.
 *
 * @see app/Services/Lesson/LessonReadScope.php
 */
final class LessonWithoutInstitutionTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Lesson $leconA;

    private Lesson $leconB;

    /** @return iterable<string, array{0: string}> */
    public static function sansEtablissement(): iterable
    {
        // `Supradmin` et `SUPRADMIN` : variantes NON strictes, qui ne sont pas
        // le supradmin plateforme.
        foreach (['enseignant', 'etudiant', 'coordinateur', 'admin', 'superAdmin', 'Supradmin', 'SUPRADMIN'] as $role) {
            yield "« $role » sans établissement" => [$role];
        }
    }

    #[DataProvider('sansEtablissement')]
    public function test_un_compte_sans_etablissement_ne_lit_aucune_lecon(string $role): void
    {
        $this->deuxEcoles();
        $compte = User::factory()->create(['institution_id' => null, 'role' => $role]);

        self::assertSame(0, $this->asTenant($compte)->getJson('/api/lessons?per_page=100')->assertOk()->json('data.total'), 'GET /lessons');
        self::assertSame(0, $this->asTenant($compte)->getJson('/api/lessons/my-courses?per_page=100')->assertOk()->json('meta.total'), 'my-courses');
        $this->asTenant($compte)->getJson("/api/lessons/{$this->leconA->id}")->assertNotFound();
        $this->asTenant($compte)->getJson("/api/lessons/{$this->leconB->id}")->assertNotFound();
    }

    public function test_le_supradmin_plateforme_lit_les_deux_etablissements(): void
    {
        $this->deuxEcoles();
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $ids = collect($this->asTenant($supradmin)->getJson('/api/lessons?per_page=100')->assertOk()->json('data.data'))->pluck('id')->all();
        self::assertContains($this->leconA->id, $ids);
        self::assertContains($this->leconB->id, $ids);
        $this->asTenant($supradmin)->getJson("/api/lessons/{$this->leconA->id}")->assertOk();
        $this->asTenant($supradmin)->getJson("/api/lessons/{$this->leconB->id}")->assertOk();
    }

    public function test_un_compte_rattache_ne_lit_que_son_etablissement(): void
    {
        $this->deuxEcoles();
        $enseignant = User::factory()->create(['institution_id' => $this->leconA->institution_id, 'role' => 'enseignant']);

        $ids = collect($this->asTenant($enseignant)->getJson('/api/lessons?per_page=100')->assertOk()->json('data.data'))->pluck('id')->all();
        self::assertSame([$this->leconA->id], $ids);
        $this->asTenant($enseignant)->getJson("/api/lessons/{$this->leconB->id}")->assertNotFound();
    }

    /**
     * Le filtre d'établissement de LessonReadScope tient seul. Le test se place
     * DÉLIBÉRÉMENT sans tenant résolu (`Sanctum::actingAs` n'émet aucun jeton,
     * donc ResolveInstitution ne pose rien) : le scope global est alors ouvert.
     * Avec un vrai jeton, ce scope masquerait déjà l'autre école, et le filtre
     * explicite ne serait pas prouvé.
     */
    public function test_sans_tenant_resolu_le_filtre_explicite_tient_seul(): void
    {
        $this->deuxEcoles();
        $enseignant = User::factory()->create(['institution_id' => $this->leconA->institution_id, 'role' => 'enseignant']);
        Sanctum::actingAs($enseignant);

        $ids = collect($this->getJson('/api/lessons?per_page=100')->assertOk()->json('data.data'))->pluck('id')->all();
        self::assertSame([$this->leconA->id], $ids, 'liste : l\'autre établissement est filtré explicitement');
        $this->getJson("/api/lessons/{$this->leconB->id}")->assertNotFound();
    }

    private function deuxEcoles(): void
    {
        $this->disableKlassciMiddleware();

        $a = Institution::factory()->create();
        $b = Institution::factory()->standalone()->create();
        $classeA = Classe::factory()->create(['institution_id' => $a->id, 'klassci_id' => 7001]);
        $classeB = Classe::factory()->create(['institution_id' => $b->id, 'klassci_id' => null]);

        $this->leconA = Lesson::factory()->published()->create(['institution_id' => $a->id, 'classe_id' => $classeA->id]);
        $this->leconB = Lesson::factory()->published()->create(['institution_id' => $b->id, 'classe_id' => $classeB->id]);
    }
}
