<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Enums\TrainingSessionStatus;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Session\TrainingSessionCrudService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #827 — créer une session de formation, de bout en bout.
 *
 * #800 a livré les tables ; rien ne permettait de s'en servir. Ce fichier garde
 * le premier parcours réellement utile du monde autonome : un responsable
 * d'école crée son Programme, puis la Période datée qui le fera vivre.
 *
 * ## Ce que le client n'a PAS le droit de dicter
 *
 * Ni `status` — une Période naît brouillon, la publication est une décision
 * séparée — ni `institution_id`, qui vient du tenant résolu. Les accepter
 * laisserait un coordinateur publier d'emblée, ou écrire chez le voisin.
 *
 * ## Pourquoi un VRAI jeton Bearer
 *
 * `Sanctum::actingAs` n'émet aucun jeton : `ResolveInstitution` ne pose alors
 * aucun tenant, le scope multi-tenant s'efface, et un test d'isolation devient
 * un faux négatif. C'est le patron de `AdminUserListTenantIsolationTest`.
 *
 * @see docs/SCHEMA_CIBLE_V2.md
 */
final class CreateTrainingSessionApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROGRAMMES = '/api/programs';

    private const PERIODES = '/api/training-sessions';

    /**
     * @return array<string, string>
     */
    private function entete(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api-session-827')->plainTextToken];
    }

    private function responsable(?Institution $institution = null): User
    {
        $institution ??= Institution::factory()->create();

        return User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'superAdmin',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chargePeriode(int $programId): array
    {
        return [
            'program_id' => $programId,
            'libelle' => 'Promotion de janvier',
            'enrollment_opens_at' => '2026-10-01',
            'enrollment_closes_at' => '2026-10-20',
            'starts_on' => '2026-11-01',
            'ends_on' => '2027-02-28',
            'certificate_available_at' => '2027-03-15',
            'min_enrollments' => 8,
            'tarif' => 110000,
            'devise' => 'XOF',
        ];
    }

    // ───────────────────────────────── le parcours nominal

    public function test_un_responsable_cree_son_programme_puis_sa_periode(): void
    {
        $responsable = $this->responsable();
        $entete = $this->entete($responsable);

        $programme = $this->withHeaders($entete)->postJson(self::PROGRAMMES, [
            'titre' => 'Comptabilité OHADA',
            'description' => 'Cycle complet, niveau intermédiaire.',
        ])->assertStatus(201);

        $programId = (int) $programme->json('data.id');

        $this->assertDatabaseHas('programs', [
            'id' => $programId,
            'titre' => 'Comptabilité OHADA',
            'institution_id' => $responsable->institution_id,
        ]);

        $periode = $this->withHeaders($entete)
            ->postJson(self::PERIODES, $this->chargePeriode($programId))
            ->assertStatus(201);

        $this->assertDatabaseHas('training_sessions', [
            'id' => (int) $periode->json('data.id'),
            'libelle' => 'Promotion de janvier',
            'institution_id' => $responsable->institution_id,
            'status' => TrainingSessionStatus::Brouillon->value,
        ]);
    }

    public function test_la_periode_expose_sa_phase_derivee(): void
    {
        $responsable = $this->responsable();
        $entete = $this->entete($responsable);
        $programme = Program::factory()->create(['institution_id' => $responsable->institution_id]);

        $reponse = $this->withHeaders($entete)
            ->postJson(self::PERIODES, $this->chargePeriode((int) $programme->getKey()))
            ->assertStatus(201);

        // La phase n'est pas stockée : si elle apparaît, c'est que l'accessor
        // est bien branché jusqu'à la réponse HTTP.
        $this->assertIsString($reponse->json('data.phase'));
    }

    // ───────────────────────────────── ce que le client ne dicte pas

    public function test_le_statut_poste_par_le_client_est_ignore(): void
    {
        $responsable = $this->responsable();
        $entete = $this->entete($responsable);
        $programme = Program::factory()->create(['institution_id' => $responsable->institution_id]);

        $charge = $this->chargePeriode((int) $programme->getKey());
        $charge['status'] = TrainingSessionStatus::Publiee->value;

        $reponse = $this->withHeaders($entete)->postJson(self::PERIODES, $charge)->assertStatus(201);

        // Publier est une décision distincte de créer.
        $this->assertDatabaseHas('training_sessions', [
            'id' => (int) $reponse->json('data.id'),
            'status' => TrainingSessionStatus::Brouillon->value,
        ]);
    }

    public function test_l_institution_postee_par_le_client_est_ignoree(): void
    {
        $voisine = Institution::factory()->create();
        $responsable = $this->responsable();
        $entete = $this->entete($responsable);
        $programme = Program::factory()->create(['institution_id' => $responsable->institution_id]);

        $charge = $this->chargePeriode((int) $programme->getKey());
        $charge['institution_id'] = $voisine->getKey();

        $reponse = $this->withHeaders($entete)->postJson(self::PERIODES, $charge)->assertStatus(201);

        $this->assertDatabaseHas('training_sessions', [
            'id' => (int) $reponse->json('data.id'),
            'institution_id' => $responsable->institution_id,
        ]);
    }

    // ───────────────────────────────── l'isolation

    public function test_on_ne_rattache_pas_une_periode_au_programme_d_une_autre_ecole(): void
    {
        $responsable = $this->responsable();
        $voisin = Program::factory()->create();

        $this->withHeaders($this->entete($responsable))
            ->postJson(self::PERIODES, $this->chargePeriode((int) $voisin->getKey()))
            ->assertStatus(422);

        $this->assertSame(0, TrainingSession::query()->withoutGlobalScopes()->count());
    }

    public function test_la_liste_ne_montre_que_les_periodes_de_son_ecole(): void
    {
        $responsable = $this->responsable();
        $sienne = TrainingSession::factory()->create([
            'institution_id' => $responsable->institution_id,
            'program_id' => Program::factory()->create([
                'institution_id' => $responsable->institution_id,
            ])->getKey(),
        ]);
        $voisine = TrainingSession::factory()->create();

        $reponse = $this->withHeaders($this->entete($responsable))
            ->getJson(self::PERIODES)
            ->assertStatus(200);

        $ids = array_column((array) $reponse->json('data'), 'id');

        $this->assertContains($sienne->getKey(), $ids);
        $this->assertNotContains($voisine->getKey(), $ids);
    }

    /**
     * Ce que le test HTTP au-dessus ne prouve PAS, et celui-ci si.
     *
     * Sur un appel authentifie, `ResolveInstitution` pose le tenant et le scope
     * global de `BelongsToInstitution` isole tout seul : retirer le filtre
     * explicite du service laisse le test HTTP au VERT. Mesure faite.
     *
     * Mais ce trait est fail-OPEN — sans tenant resolu, il journalise un
     * avertissement et laisse passer (`BelongsToInstitution:84`). Hors requete
     * — un job, une commande — l isolation reposerait alors sur rien.
     *
     * Ce test appelle donc le service SANS tenant, et prouve que le filtre
     * explicite borne quand meme. Retirez-le : il rougit.
     */
    public function test_le_service_borne_par_etablissement_meme_sans_tenant_resolu(): void
    {
        $responsable = $this->responsable();
        TrainingSession::factory()->create([
            'institution_id' => $responsable->institution_id,
            'program_id' => Program::factory()->create([
                'institution_id' => $responsable->institution_id,
            ])->getKey(),
        ]);
        $voisine = TrainingSession::factory()->create();

        // Aucun middleware n a tourne : le TenantManager est vide.
        app(TenantManager::class)->reset();

        $page = app(TrainingSessionCrudService::class)->listerLesPeriodes($responsable, 25);
        $ids = array_map(static fn (TrainingSession $p): int => (int) $p->getKey(), $page->items());

        $this->assertNotContains(
            (int) $voisine->getKey(),
            $ids,
            'Sans tenant resolu, le scope global s efface : seul le filtre explicite du service borne encore.'
        );
    }

    // ───────────────────────────────── qui a le droit

    public function test_un_etudiant_ne_cree_pas_de_periode(): void
    {
        $institution = Institution::factory()->create();
        $etudiant = User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'etudiant',
        ]);
        $programme = Program::factory()->create(['institution_id' => $institution->getKey()]);

        $this->withHeaders($this->entete($etudiant))
            ->postJson(self::PERIODES, $this->chargePeriode((int) $programme->getKey()))
            ->assertStatus(403);
    }

    public function test_un_anonyme_ne_cree_rien(): void
    {
        $this->postJson(self::PROGRAMMES, ['titre' => 'Tentative'])->assertStatus(401);
    }

    // ───────────────────────────────── la validation des dates

    public function test_des_dates_desordonnees_sont_refusees_avant_la_base(): void
    {
        $responsable = $this->responsable();
        $programme = Program::factory()->create(['institution_id' => $responsable->institution_id]);

        $charge = $this->chargePeriode((int) $programme->getKey());
        $charge['ends_on'] = '2026-10-01';

        // 422 et non 500 : la contrainte de base est le filet, pas l'interface.
        $this->withHeaders($this->entete($responsable))
            ->postJson(self::PERIODES, $charge)
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_on');
    }

    public function test_une_periode_sans_dates_est_acceptee(): void
    {
        $responsable = $this->responsable();
        $programme = Program::factory()->create(['institution_id' => $responsable->institution_id]);

        // Un brouillon se crée avant que les dates soient arrêtées.
        $this->withHeaders($this->entete($responsable))
            ->postJson(self::PERIODES, [
                'program_id' => $programme->getKey(),
                'libelle' => 'Promotion à dater',
            ])
            ->assertStatus(201);
    }
}
