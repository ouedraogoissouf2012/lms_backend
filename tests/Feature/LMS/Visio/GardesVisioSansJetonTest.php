<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Cinq gardes visio refusaient un compte sans jeton KLASSCI par `authorize() = false`.
 *
 * En production, ce refus sortait en `403 « Une erreur est survenue. »` par le rendu
 * générique `\Throwable` (`bootstrap/app.php`), avec un `Log::error` et sa trace.
 * Or le jeton n'est une condition du TRAVAIL que pour deux routes, dont la clé est
 * KLASSCI : l'activation (l'emploi du temps KLASSCI) et la bascule (la séance sous
 * son identifiant KLASSCI). Elles rendent désormais le 401 motivé de #806, avant
 * toute écriture. Les trois autres n'appellent pas KLASSCI : un compte sans jeton
 * y passe, et c'est le SERVICE qui décide de l'accès (établissement, propriétaire).
 *
 * Volet autonome : un compte d'école autonome n'a JAMAIS de jeton. La garde le
 * bloquait sur des routes qui n'en ont pas besoin. Les comptes ci-dessous sont
 * créés sans jeton : `UserFactory` en donne un à tout compte, et c'est ce qui
 * cachait le défaut.
 *
 * Vrai jeton porteur (`asTenant`), jamais `Sanctum::actingAs`.
 */
final class GardesVisioSansJetonTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $ecole;

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => ['klassci'];
        yield 'ecole autonome' => ['standalone'];
    }

    private function ecole(string $volet): Institution
    {
        $this->disableKlassciMiddleware();
        Http::fake();

        return $this->ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
    }

    /** @param  array<string, mixed>  $attributs */
    private function sansJeton(string $role, array $attributs = []): User
    {
        return User::factory()->{$role}()->for($this->ecole)->create(['klassci_token' => null] + $attributs);
    }

    /** Une séance dont `$enseignant` est propriétaire, dans les deux volets. */
    private function seanceDe(User $enseignant, string $volet): Seance
    {
        return Seance::factory()->for($this->ecole)->withVisio()->create($volet === 'standalone'
            ? ['created_by' => $enseignant->id, 'klassci_enseignant_id' => null, 'klassci_seance_id' => null]
            : ['klassci_enseignant_id' => $enseignant->klassci_id]);
    }

    #[DataProvider('volets')]
    public function test_activer_la_visio_sans_jeton_rend_le_401_motive_sans_rien_ecrire(string $volet): void
    {
        $this->ecole($volet);
        $avant = DB::table('seances')->count();

        $this->asTenant($this->sansJeton('teacher'))
            ->postJson('/api/lms/seances/4242/activate-visio')
            ->assertUnauthorized()
            ->assertExactJson(MissingKlassciTokenException::payload());

        Http::assertNothingSent();
        self::assertSame($avant, DB::table('seances')->count());
    }

    #[DataProvider('volets')]
    public function test_basculer_la_visio_sans_jeton_rend_le_401_et_ne_cree_aucune_seance_fantome(string $volet): void
    {
        $this->ecole($volet);
        // Une vraie séance locale : la bascule sous son identifiant créait une
        // séance fantôme `klassci_seance_id = id` et répondait « activée ».
        $locale = Seance::factory()->for($this->ecole)->create(['klassci_seance_id' => null, 'visio_enabled' => false]);

        $this->asTenant($this->sansJeton('coordinator'))
            ->postJson("/api/lms/seances/{$locale->id}/toggle-visio", ['enabled' => true])
            ->assertUnauthorized()
            ->assertExactJson(MissingKlassciTokenException::payload());

        self::assertSame(1, DB::table('seances')->count());
        self::assertFalse((bool) DB::table('seances')->where('id', $locale->id)->value('visio_enabled'));
        Http::assertNothingSent();
    }

    #[DataProvider('volets')]
    public function test_valider_sans_jeton_passe_la_garde_et_le_service_decide(string $volet): void
    {
        $this->ecole($volet);
        $coordinateur = $this->sansJeton('coordinator');
        $sansVisio = Seance::factory()->for($this->ecole)->create(['visio_enabled' => false]);

        // Le motif vient du SERVICE : la garde n'a plus refusé.
        $this->asTenant($coordinateur)
            ->postJson("/api/lms/seances/{$sansVisio->id}/validate-participant", ['user_id' => $coordinateur->id])
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'authorized' => false,
                'reason' => 'visio_not_enabled',
                'message' => 'Visioconférence non activée pour cette séance',
            ]);
    }

    #[DataProvider('volets')]
    public function test_un_enseignant_sans_jeton_valide_sur_sa_propre_seance(string $volet): void
    {
        $this->ecole($volet);
        $a = $this->sansJeton('teacher', ['klassci_id' => 777]);

        $this->asTenant($a)
            ->postJson("/api/lms/seances/{$this->seanceDe($a, $volet)->id}/validate-participant", ['user_id' => $a->id])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'authorized' => true,
                'role' => 'teacher',
                'message' => 'Enseignant ou coordinateur autorisé',
            ]);
    }

    #[DataProvider('volets')]
    public function test_l_enseignant_b_ne_valide_pas_sur_la_seance_de_l_enseignant_a(string $volet): void
    {
        $this->ecole($volet);
        $a = $this->sansJeton('teacher', ['klassci_id' => 777]);
        $b = $this->sansJeton('teacher', ['klassci_id' => 888]);

        $this->asTenant($b)
            ->postJson("/api/lms/seances/{$this->seanceDe($a, $volet)->id}/validate-participant", ['user_id' => $b->id])
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'authorized' => false,
                'reason' => 'actor_not_authorized',
                'message' => 'Vous ne pouvez pas valider cette séance',
            ]);
    }

    #[DataProvider('volets')]
    public function test_l_enseignant_b_ne_synchronise_pas_les_presences_de_la_seance_de_l_enseignant_a(string $volet): void
    {
        $this->ecole($volet);
        $a = $this->sansJeton('teacher', ['klassci_id' => 777]);
        $b = $this->sansJeton('teacher', ['klassci_id' => 888]);

        $this->asTenant($b)
            ->postJson('/api/lms/attendances/from-video-session', [
                'seance_cours_id' => $this->seanceDe($a, $volet)->id,
                'date' => '2026-10-30',
                'participants' => [['etudiant_id' => 100, 'statut' => 'present']],
            ])
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'reason' => 'actor_not_authorized']);

        self::assertSame(0, DB::table('esbtp_attendance')->count());
    }

    public function test_un_enseignant_klassci_sans_jeton_synchronise_les_presences_de_sa_seance(): void
    {
        $this->ecole('klassci');
        $a = $this->sansJeton('teacher', ['klassci_id' => 777]);
        $classe = Classe::factory()->for($this->ecole)->create(['klassci_id' => 55]);
        $eleve = User::factory()->student()->for($this->ecole)->create(['klassci_id' => 100]);
        $classe->etudiants()->attach($eleve->id, ['statut' => 'actif']);
        $seance = Seance::factory()->for($this->ecole)->create([
            'klassci_enseignant_id' => 777,
            'klassci_classe_id' => 55,
        ]);

        $this->asTenant($a)
            ->postJson('/api/lms/attendances/from-video-session', [
                'seance_cours_id' => $seance->id,
                'date' => '2026-10-30',
                'participants' => [['etudiant_id' => 100, 'statut' => 'present']],
            ])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.errors', []);
    }

    #[DataProvider('volets')]
    public function test_le_rappel_de_seance_n_exige_pas_de_jeton(string $volet): void
    {
        $this->ecole($volet);

        $this->asTenant($this->sansJeton('teacher'))
            ->postJson('/api/lms/notifications/send-session-reminder', [
                'seance_cours_id' => 12,
                'channels' => ['app'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sent_count', 0);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function routesDuPersonnel(): iterable
    {
        foreach (['klassci', 'standalone'] as $volet) {
            yield "{$volet} : activate" => [$volet, '/api/lms/seances/4242/activate-visio', []];
            yield "{$volet} : toggle" => [$volet, '/api/lms/seances/4242/toggle-visio', ['enabled' => true]];
            yield "{$volet} : validate" => [$volet, '/api/lms/seances/4242/validate-participant', ['user_id' => 1]];
            yield "{$volet} : sync" => [$volet, '/api/lms/attendances/from-video-session', []];
        }
    }

    /** Retirer le jeton n'ouvre rien aux élèves : le rôle tient toujours. */
    #[DataProvider('routesDuPersonnel')]
    public function test_un_eleve_reste_refuse(string $volet, string $route, array $corps): void
    {
        $this->ecole($volet);

        $this->asTenant($this->sansJeton('student'))
            ->postJson($route, $corps)
            ->assertForbidden();

        self::assertSame(0, DB::table('seances')->count());
    }
}
