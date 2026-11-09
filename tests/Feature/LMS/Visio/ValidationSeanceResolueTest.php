<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * La validation d'un participant juge la séance que l'appelant a résolue.
 *
 * L'identifiant reçu d'une route se résout UNE fois, par la traduction du
 * modèle (`Seance::localIdFor`, précédence au local, bornage à
 * l'établissement), et c'est cette séance-là que la validation reçoit. Les
 * deux numérotations d'une séance sont indépendantes (ADR-805-01) : ces tests
 * en construisent une rencontre, pour vérifier que la décision porte sur la
 * séance désignée par l'id local.
 *
 * Identifiants posés explicitement : la rencontre des deux numérotations est
 * la donnée même du cas, et un identifiant attribué par le moteur ne la
 * garantirait pas.
 */
final class ValidationSeanceResolueTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ID_SEANCE = 9002;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_rejoindre_juge_la_seance_que_l_appelant_a_resolue(): void
    {
        [, $eleve] = $this->deuxSeances();

        $this->asTenant($eleve)
            ->postJson('/api/lms/seances/'.self::ID_SEANCE.'/join')
            ->assertForbidden()
            ->assertJsonPath('reason', 'not_enrolled');
    }

    public function test_valider_un_participant_juge_la_seance_que_la_route_designe(): void
    {
        [$ecole, $eleve] = $this->deuxSeances();
        $coordinateur = User::factory()->coordinator()->create(['institution_id' => $ecole->getKey()]);

        $this->asTenant($coordinateur)
            ->postJson('/api/lms/seances/'.self::ID_SEANCE.'/validate-participant', ['user_id' => $eleve->getKey()])
            ->assertForbidden()
            ->assertJsonPath('authorized', false)
            ->assertJsonPath('reason', 'not_enrolled');
    }

    public function test_l_eleve_inscrit_a_la_classe_de_la_seance_designee_est_admis(): void
    {
        // Le chemin nominal, avec la même rencontre de numérotations : la
        // validation admet l'élève de la classe de la séance désignée.
        [$ecole, , $inscritA] = $this->deuxSeances();
        $coordinateur = User::factory()->coordinator()->create(['institution_id' => $ecole->getKey()]);

        $this->asTenant($coordinateur)
            ->postJson('/api/lms/seances/'.self::ID_SEANCE.'/validate-participant', ['user_id' => $inscritA->getKey()])
            ->assertOk()
            ->assertJsonPath('authorized', true)
            ->assertJsonPath('role', 'student');
    }

    public function test_la_validation_ne_relit_pas_la_seance_par_les_deux_numerotations(): void
    {
        // L'ordre dans lequel un moteur rend les lignes d'un « id OU
        // klassci_seance_id » dépend de son plan : SQLite lit le terme local
        // d'abord, MySQL peut lire l'autre. Les tests ci-dessus jugent le
        // résultat ; celui-ci juge la FORME, indépendante du moteur : aucune
        // lecture de séance ne combine les deux numérotations.
        [$ecole, $eleve] = $this->deuxSeances();
        $coordinateur = User::factory()->coordinator()->create(['institution_id' => $ecole->getKey()]);
        $lectures = [];
        DB::listen(static function ($requete) use (&$lectures): void {
            $sql = strtolower($requete->sql);
            if (str_contains($sql, 'seances') && str_contains($sql, 'klassci_seance_id') && str_contains($sql, ' or ')) {
                $lectures[] = $requete->sql;
            }
        });

        // Les deux requêtes doivent ATTEINDRE la validation : un refus plus
        // tôt (rôle, porte, requête) laisserait la liste vide sans rien prouver.
        $this->asTenant($coordinateur)
            ->postJson('/api/lms/seances/'.self::ID_SEANCE.'/validate-participant', ['user_id' => $eleve->getKey()])
            ->assertForbidden()
            ->assertJsonPath('reason', 'not_enrolled');
        $this->asTenant($eleve)
            ->postJson('/api/lms/seances/'.self::ID_SEANCE.'/join')
            ->assertForbidden()
            ->assertJsonPath('reason', 'not_enrolled');

        self::assertSame([], $lectures);
    }

    public function test_dans_une_ecole_creee_depuis_le_lms_la_validation_ne_relit_pas_la_seance(): void
    {
        // L'autre volet : une séance LOCALE (aucun identifiant KLASSCI). La
        // résolution ne dépend pas du mode ; ce test le vérifie, sur un chemin
        // qui atteint la décision (un coordinateur validé en modérateur).
        $ecole = Institution::factory()->standalone()->create();
        $seance = Seance::factory()->visioActive()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_seance_id' => null,
            'klassci_classe_id' => null,
            'is_active' => true,
        ]);
        $coordinateur = User::factory()->coordinator()->create(['institution_id' => $ecole->getKey(), 'klassci_token' => null]);
        $lectures = [];
        DB::listen(static function ($requete) use (&$lectures): void {
            $sql = strtolower($requete->sql);
            if (str_contains($sql, 'seances') && str_contains($sql, 'klassci_seance_id') && str_contains($sql, ' or ')) {
                $lectures[] = $requete->sql;
            }
        });

        $this->asTenant($coordinateur)
            ->postJson('/api/lms/seances/'.$seance->getKey().'/validate-participant', ['user_id' => $coordinateur->getKey()])
            ->assertOk()
            ->assertJsonPath('authorized', true)
            ->assertJsonPath('role', 'moderator');

        self::assertSame([], $lectures);
    }

    /**
     * Deux séances d'une même école. La séance désignée par l'id local
     * `ID_SEANCE` est celle de la classe A ; l'autre, de la classe B, porte ce
     * même nombre comme identifiant KLASSCI.
     *
     * @return array{0: Institution, 1: User, 2: User}
     */
    private function deuxSeances(): array
    {
        $ecole = Institution::factory()->create();
        $classeA = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => 81002]);
        $classeB = Classe::factory()->create(['institution_id' => $ecole->getKey(), 'klassci_id' => 81001]);

        Seance::factory()->visioActive()->create([
            'id' => self::ID_SEANCE - 1,
            'institution_id' => $ecole->getKey(),
            'klassci_seance_id' => self::ID_SEANCE,
            'klassci_classe_id' => 81001,
            'is_active' => true,
        ]);
        Seance::factory()->visioActive()->create([
            'id' => self::ID_SEANCE,
            'institution_id' => $ecole->getKey(),
            'klassci_seance_id' => 77001,
            'klassci_classe_id' => 81002,
            'is_active' => true,
        ]);

        $eleveB = User::factory()->student()->create(['institution_id' => $ecole->getKey()]);
        $eleveA = User::factory()->student()->create(['institution_id' => $ecole->getKey()]);
        $this->inscrire($eleveB, $classeB);
        $this->inscrire($eleveA, $classeA);

        return [$ecole, $eleveB, $eleveA];
    }

    private function inscrire(User $eleve, Classe $classe): void
    {
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->getKey(),
            'user_id' => $eleve->getKey(),
            'statut' => 'actif',
            'date_inscription' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
