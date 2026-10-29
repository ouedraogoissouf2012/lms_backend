<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #943 — `/lessons/my-courses` entre deux établissements, un par volet.
 *
 * ## Pourquoi deux écoles, et pourquoi ces deux-là
 *
 * Aucun test de la route ne mettait en jeu deux établissements (§1.3 et §5 des
 * standards). Les deux écoles retenues sont aussi les deux volets du système :
 *
 *   - **A, mode klassci** : l'apprenant n'est inscrit QUE par le cache de sync
 *     KLASSCI (`user_classes`), comme après une connexion KLASSCI ;
 *   - **B, mode standalone** : pivot local `classe_etudiant`, classe et comptes
 *     sans aucune identité KLASSCI.
 *
 * L'inscription ne lit pas le mode (`CompositeEnrollmentSource` : pivot, puis
 * cache) ; ces tests ne le lisent pas non plus. Ils vérifient que le même code
 * tient dans les deux mondes ET qu'aucun ne voit l'autre.
 *
 * ## Vrai jeton porteur
 *
 * {@see ActsAsTenantUser::asTenant()} émet un jeton réel : `ResolveInstitution`
 * pose le tenant et le scope `BelongsToInstitution` filtre. `Sanctum::actingAs`
 * ne poserait aucun tenant, et ces tests deviendraient verts sans rien prouver.
 *
 * @see app/Services/Lesson/LessonListService.php
 * @see app/Services/Enrollment/CompositeEnrollmentSource.php
 */
final class MyCoursesDeuxEcolesTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const KLASSCI_CLASSE_A = 7001;

    private Institution $ecoleKlassci;

    private Institution $ecoleAutonome;

    private Classe $classeA;

    private Classe $classeB;

    /** @var list<int> */
    private array $leconsA;

    /** @var list<int> */
    private array $leconsB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->ecoleKlassci = Institution::factory()->create();
        $this->ecoleAutonome = Institution::factory()->standalone()->create();
        self::assertSame(InstitutionMode::Klassci, $this->ecoleKlassci->mode);
        self::assertSame(InstitutionMode::Standalone, $this->ecoleAutonome->mode);

        $this->classeA = Classe::factory()->create([
            'institution_id' => $this->ecoleKlassci->id,
            'klassci_id' => self::KLASSCI_CLASSE_A,
        ]);
        $this->classeB = Classe::factory()->create([
            'institution_id' => $this->ecoleAutonome->id,
            'klassci_id' => null,
        ]);

        $this->leconsA = $this->leconsPubliees($this->ecoleKlassci, $this->classeA, 2);
        $this->leconsB = $this->leconsPubliees($this->ecoleAutonome, $this->classeB, 3);
    }

    public function test_l_apprenant_klassci_ne_voit_que_les_lecons_de_sa_classe(): void
    {
        $apprenant = $this->compte($this->ecoleKlassci, 'etudiant', klassciId: 910001);
        $this->inscrireParLeCacheKlassci($apprenant, $this->ecoleKlassci, self::KLASSCI_CLASSE_A);

        self::assertEqualsCanonicalizing($this->leconsA, $this->idsVusPar($apprenant));
    }

    public function test_l_apprenant_autonome_ne_voit_que_les_lecons_de_sa_classe(): void
    {
        $apprenant = $this->compte($this->ecoleAutonome, 'etudiant', klassciId: null);
        $this->classeB->etudiants()->attach($apprenant->id, ['statut' => 'actif']);

        self::assertEqualsCanonicalizing($this->leconsB, $this->idsVusPar($apprenant));
    }

    /**
     * La vue du personnel n'a AUCUNE restriction de classe : seule la frontière
     * de l'établissement la borne. C'est donc ici que l'isolation se joue.
     */
    public function test_le_personnel_d_une_ecole_ne_voit_jamais_les_lecons_de_l_autre(): void
    {
        $profA = $this->compte($this->ecoleKlassci, 'enseignant', klassciId: 920001);
        $profB = $this->compte($this->ecoleAutonome, 'enseignant', klassciId: null);

        self::assertEqualsCanonicalizing($this->leconsA, $this->idsVusPar($profA));
        self::assertEqualsCanonicalizing($this->leconsB, $this->idsVusPar($profB));
    }

    /**
     * Le cache KLASSCI ne porte qu'un identifiant de classe KLASSCI, unique
     * seulement par établissement (#707). Un apprenant de B dont le cache nomme
     * le numéro de la classe de A ne doit rien résoudre chez A.
     */
    public function test_le_cache_klassci_ne_resout_pas_une_classe_d_une_autre_ecole(): void
    {
        $apprenant = $this->compte($this->ecoleAutonome, 'etudiant', klassciId: null);
        $this->inscrireParLeCacheKlassci($apprenant, $this->ecoleAutonome, self::KLASSCI_CLASSE_A);

        self::assertSame([], $this->idsVusPar($apprenant));
    }

    /** @return list<int> */
    private function idsVusPar(User $compte): array
    {
        $response = $this->asTenant($compte)
            ->getJson('/api/lessons/my-courses?per_page=100')
            ->assertOk()
            ->assertJsonPath('success', true);

        /** @var list<int> $ids */
        $ids = collect($response->json('data'))->pluck('id')->all();

        return $ids;
    }

    private function compte(Institution $ecole, string $role, ?int $klassciId): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->id,
            'role' => $role,
            'klassci_id' => $klassciId,
        ]);
    }

    private function inscrireParLeCacheKlassci(User $apprenant, Institution $ecole, int $klassciClasseId): void
    {
        UserClass::create([
            'user_id' => $apprenant->id,
            'klassci_classe_id' => $klassciClasseId,
            'classe_nom' => 'Classe KLASSCI '.$klassciClasseId,
            'institution_id' => $ecole->id,
            'synced_at' => now(),
        ]);
    }

    /** @return list<int> */
    private function leconsPubliees(Institution $ecole, Classe $classe, int $nombre): array
    {
        /** @var list<int> $ids */
        $ids = Lesson::factory()->published()->count($nombre)->create([
            'institution_id' => $ecole->id,
            'classe_id' => $classe->id,
        ])->pluck('id')->all();

        return $ids;
    }
}
