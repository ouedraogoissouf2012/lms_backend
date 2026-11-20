<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\TrainingSessionStatus;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * #1208, point 1 — rattacher une classe à une Période de formation.
 *
 * `classes.training_session_id` n'avait aucun écrivain : les règles qui le lisent (une
 * Période close ferme les codes, `is_active` d'une classe) ne s'appliquaient jamais.
 * Deux écrivains désormais : la création d'une classe, et le geste nommé
 * `PUT /classes/{classe}/periode`.
 *
 * Les deux volets : école autonome (permis, dans l'établissement) et école KLASSCI
 * (la porte du catalogue refuse, 403 `catalogue_managed_by_klassci`). Vrai jeton.
 */
final class RattacherUneClasseAUnePeriodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_classe_se_rattache_a_une_periode_de_son_ecole(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $classe = $this->classeDe($ecole);
        $periode = $this->periodeDe($ecole, TrainingSessionStatus::Publiee);

        $this->rattacher($this->responsable($ecole), $classe, $periode->id)
            ->assertOk()
            ->assertJsonPath('data.id', $classe->id)
            ->assertJsonPath('data.training_session_id', $periode->id);

        self::assertSame($periode->id, $this->periodeEnBase($classe));
    }

    /** L'effet réel : `is_active` de la classe suit sa Période, au lieu de rester nul. */
    public function test_rattachee_a_une_periode_publiee_la_classe_devient_active(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $responsable = $this->responsable($ecole);
        $classe = $this->classeDe($ecole);
        $periode = $this->periodeDe($ecole, TrainingSessionStatus::Publiee);

        self::assertNull($this->activiteListee($responsable, $classe));

        $this->rattacher($responsable, $classe, $periode->id)->assertOk();

        self::assertTrue($this->activiteListee($responsable, $classe));
    }

    public function test_detacher_garde_les_inscriptions_deja_faites(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $periode = $this->periodeDe($ecole, TrainingSessionStatus::Publiee);
        $classe = $this->classeDe($ecole, ['training_session_id' => $periode->id]);
        $eleve = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'etudiant', 'klassci_token' => null]);
        DB::table('classe_etudiant')->insert(['classe_id' => $classe->id, 'user_id' => $eleve->id, 'statut' => 'actif',
            'date_inscription' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()]);

        $this->rattacher($this->responsable($ecole), $classe, null)
            ->assertOk()
            ->assertJsonPath('data.training_session_id', null);

        self::assertNull($this->periodeEnBase($classe));
        self::assertSame(1, DB::table('classe_etudiant')->where('classe_id', $classe->id)->count());
    }

    /** @return iterable<string, array{TrainingSessionStatus}> */
    public static function periodesQuiRecoivent(): iterable
    {
        yield 'brouillon' => [TrainingSessionStatus::Brouillon];
        yield 'publiée' => [TrainingSessionStatus::Publiee];
    }

    #[DataProvider('periodesQuiRecoivent')]
    public function test_une_periode_qui_recoit_encore_est_acceptee(TrainingSessionStatus $statut): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $classe = $this->classeDe($ecole);

        $this->rattacher($this->responsable($ecole), $classe, $this->periodeDe($ecole, $statut)->id)->assertOk();
    }

    /** @return iterable<string, array{TrainingSessionStatus}> */
    public static function periodesFermees(): iterable
    {
        yield 'annulée' => [TrainingSessionStatus::Annulee];
        yield 'clôturée' => [TrainingSessionStatus::Cloturee];
        yield 'archivée' => [TrainingSessionStatus::Archivee];
        yield 'purgée' => [TrainingSessionStatus::Purgee];
    }

    #[DataProvider('periodesFermees')]
    public function test_une_periode_fermee_est_une_saisie_invalide(TrainingSessionStatus $statut): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $classe = $this->classeDe($ecole);

        $this->rattacher($this->responsable($ecole), $classe, $this->periodeDe($ecole, $statut)->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('training_session_id');

        self::assertNull($this->periodeEnBase($classe));
    }

    /** Une Période d'une autre école et une Période inexistante rendent le MÊME refus. */
    public function test_une_periode_d_une_autre_ecole_ne_se_distingue_pas_d_une_inexistante(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $responsable = $this->responsable($ecole);
        $classe = $this->classeDe($ecole);
        $ailleurs = $this->periodeDe(Institution::factory()->standalone()->create(), TrainingSessionStatus::Publiee);

        $autreEcole = $this->rattacher($responsable, $classe, $ailleurs->id)->assertStatus(422);
        $inexistante = $this->rattacher($responsable, $classe, $ailleurs->id + 1000)->assertStatus(422);

        self::assertSame($autreEcole->json('errors.training_session_id'), $inexistante->json('errors.training_session_id'));
        self::assertNull($this->periodeEnBase($classe));
    }

    public function test_une_classe_d_une_autre_ecole_est_introuvable(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $classeAilleurs = $this->classeDe(Institution::factory()->standalone()->create());

        $this->rattacher($this->responsable($ecole), $classeAilleurs, $this->periodeDe($ecole, TrainingSessionStatus::Publiee)->id)
            ->assertNotFound();

        self::assertNull($this->periodeEnBase($classeAilleurs));
    }

    /** Un corps vide ne dit pas si l'on veut détacher : il est refusé, rien n'est effacé. */
    public function test_un_corps_sans_periode_est_refuse(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $periode = $this->periodeDe($ecole, TrainingSessionStatus::Publiee);
        $classe = $this->classeDe($ecole, ['training_session_id' => $periode->id]);

        $this->withToken($this->jeton($this->responsable($ecole)))
            ->putJson("/api/classes/{$classe->id}/periode", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('training_session_id');

        self::assertSame($periode->id, $this->periodeEnBase($classe));
    }

    public function test_une_classe_nait_rattachee_a_sa_periode(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $periode = $this->periodeDe($ecole, TrainingSessionStatus::Publiee);

        $id = $this->withToken($this->jeton($this->responsable($ecole)))
            ->postJson('/api/classes', ['libelle' => 'BTS 1', 'training_session_id' => $periode->id])
            ->assertCreated()
            ->json('data.id');

        self::assertSame($periode->id, DB::table('classes')->where('id', $id)->value('training_session_id'));
    }

    /** Une Période refusée à la création ne laisse aucune classe à moitié créée. */
    public function test_une_creation_avec_une_periode_fermee_ne_cree_rien(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $periode = $this->periodeDe($ecole, TrainingSessionStatus::Cloturee);

        $this->withToken($this->jeton($this->responsable($ecole)))
            ->postJson('/api/classes', ['libelle' => 'BTS 1', 'training_session_id' => $periode->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('training_session_id');

        self::assertSame(0, DB::table('classes')->where('institution_id', $ecole->id)->count());
    }

    /** École KLASSCI : la porte du catalogue refuse le geste, comme tout le catalogue local. */
    public function test_ecole_klassci_le_rattachement_est_refuse(): void
    {
        $ecole = Institution::factory()->create();
        $classe = $this->classeDe($ecole, ['klassci_id' => 55]);
        $periode = $this->periodeDe($ecole, TrainingSessionStatus::Publiee);

        $this->rattacher($this->responsable($ecole), $classe, $periode->id)
            ->assertForbidden()
            ->assertJsonPath('reason', 'catalogue_managed_by_klassci');

        self::assertNull($this->periodeEnBase($classe));
    }

    /** @return TestResponse<Response> */
    private function rattacher(User $responsable, Classe $classe, ?int $periode): TestResponse
    {
        return $this->withToken($this->jeton($responsable))
            ->putJson("/api/classes/{$classe->id}/periode", ['training_session_id' => $periode]);
    }

    private function activiteListee(User $responsable, Classe $classe): ?bool
    {
        $classes = $this->withToken($this->jeton($responsable))->getJson('/api/proxy/classes')->assertOk()->json('data');
        self::assertIsArray($classes);

        foreach ($classes as $ligne) {
            if (is_array($ligne) && ($ligne['id'] ?? null) === $classe->id) {
                $actif = $ligne['is_active'] ?? null;

                return is_bool($actif) ? $actif : null;
            }
        }

        self::fail("La classe {$classe->id} n'est pas listée.");
    }

    private function periodeEnBase(Classe $classe): ?int
    {
        $valeur = DB::table('classes')->where('id', $classe->id)->value('training_session_id');

        return $valeur === null ? null : (int) $valeur;
    }

    /** @param  array<string, mixed>  $attributs */
    private function classeDe(Institution $ecole, array $attributs = []): Classe
    {
        return Classe::factory()->create($attributs + ['institution_id' => $ecole->id, 'klassci_id' => null]);
    }

    private function periodeDe(Institution $ecole, TrainingSessionStatus $statut): TrainingSession
    {
        return TrainingSession::factory()->create(['institution_id' => $ecole->id, 'status' => $statut]);
    }

    private function responsable(Institution $ecole): User
    {
        // Sans jeton KLASSCI : la re-synchronisation n'a rien à faire ici.
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null]);
    }

    private function jeton(User $user): string
    {
        return $user->createToken('rattachement-1208')->plainTextToken;
    }
}
