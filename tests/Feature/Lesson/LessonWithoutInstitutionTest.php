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
use App\Http\Middleware\EnsureSchoolMemberOrPlatformRoute;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #942 — un compte sans établissement ne lit AUCUNE leçon, supradmin plateforme
 * compris.
 *
 * Pour un tel compte, `ResolveInstitution` ne pose aucun tenant (#972) et le
 * scope global est ouvert par défaut. La porte des comptes sans établissement
 * le refuse avant tout contrôleur. Derrière elle, le filtre de LessonReadScope
 * (le `null` absorbant d'ADR-792-01) reste en place ; sa preuve sans passer par
 * la porte appartient au lot de défense en profondeur.
 *
 * Deux établissements, un par volet (klassci et standalone) : le compte n'en
 * voit aucun. Vrai jeton porteur.
 *
 * @see app/Http/Middleware/EnsureSchoolMemberOrPlatformRoute.php
 * @see app/Services/Lesson/LessonReadScope.php
 */
final class LessonWithoutInstitutionTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Lesson $leconA;

    private Lesson $leconB;

    private Lesson $brouillonA;

    /** @return iterable<string, array{0: string}> */
    public static function sansEtablissement(): iterable
    {
        // `Supradmin` et `SUPRADMIN` : variantes NON strictes, qui ne sont pas
        // le supradmin plateforme. '' : le rôle absent que la base admet
        // (`users.role` est NOT NULL).
        foreach (['enseignant', 'etudiant', 'coordinateur', 'admin', 'superAdmin', 'Supradmin', 'SUPRADMIN', ''] as $role) {
            yield "« $role » sans établissement" => [$role];
        }
    }

    #[DataProvider('sansEtablissement')]
    public function test_un_compte_sans_etablissement_ne_lit_aucune_lecon(string $role): void
    {
        $this->deuxEcoles();
        $compte = User::factory()->create(['institution_id' => null, 'role' => $role]);

        // Le même refus de la porte partout, leçon publiée, brouillon ou liste :
        // la réponse ne dit pas qu'un brouillon existe.
        foreach (['/api/lessons?per_page=100', '/api/lessons/my-courses?per_page=100', "/api/lessons/{$this->leconA->id}",
            "/api/lessons/{$this->leconB->id}", "/api/lessons/{$this->brouillonA->id}"] as $url) {
            $this->asTenant($compte)->getJson($url)
                ->assertForbidden()->assertExactJson(['success' => false, 'message' => EnsureSchoolMemberOrPlatformRoute::MESSAGE, 'reason' => EnsureSchoolMemberOrPlatformRoute::REASON]);
        }
    }

    /**
     * Le supradmin plateforme gère les écoles, il ne lit AUCUN contenu (décision
     * produit) : la porte des comptes sans établissement le refuse, dans les deux
     * écoles, avant toute lecture.
     */
    public function test_le_supradmin_plateforme_ne_lit_les_lecons_d_aucun_etablissement(): void
    {
        $this->deuxEcoles();
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $this->asTenant($supradmin)->getJson('/api/lessons?per_page=100')
            ->assertForbidden()->assertJsonPath('message', EnsureSchoolMemberOrPlatformRoute::MESSAGE);
        $this->asTenant($supradmin)->getJson("/api/lessons/{$this->leconA->id}")->assertForbidden()->assertJsonMissingPath('data');
        $this->asTenant($supradmin)->getJson("/api/lessons/{$this->leconB->id}")->assertForbidden()->assertJsonMissingPath('data');
    }

    public function test_un_compte_rattache_ne_lit_que_son_etablissement(): void
    {
        $this->deuxEcoles();
        $enseignant = User::factory()->create(['institution_id' => $this->leconA->institution_id, 'role' => 'enseignant']);

        $ids = collect($this->asTenant($enseignant)->getJson('/api/lessons?per_page=100')->assertOk()->json('data.data'))->pluck('id')->all();
        self::assertEqualsCanonicalizing([$this->leconA->id, $this->brouillonA->id], $ids, 'son établissement, brouillon compris pour le personnel');
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
        self::assertEqualsCanonicalizing([$this->leconA->id, $this->brouillonA->id], $ids, 'liste : l\'autre établissement est filtré explicitement');
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
        $this->brouillonA = Lesson::factory()->create(['institution_id' => $a->id, 'classe_id' => $classeA->id, 'status' => 'draft']);
    }
}
