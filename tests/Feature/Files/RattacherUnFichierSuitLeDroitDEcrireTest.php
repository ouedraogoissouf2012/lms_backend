<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Rattacher un fichier à une cible (`POST /api/files/upload` avec
 * `fileable_type` et `fileable_id`) suit le droit d'ÉCRIRE sur cette cible.
 *
 * - `lesson` : l'enseignant auteur de la leçon, et lui seul (#1167) ; le
 *   coordinateur et l'admin d'école consultent le contenu, ils n'y déposent rien.
 * - `forum_topic`, `forum_post` : la règle des gardes de modification du forum
 *   (`ChecksForumAuthorization`), l'auteur ou un admin de l'école.
 * - Une cible absente, ou hors de l'école du compte, reçoit la même réponse
 *   qu'une cible sur laquelle le compte n'écrit pas.
 * - Sans cible, le fichier personnel est inchangé ; une paire partielle n'est
 *   pas vérifiable et reçoit une 422.
 *
 * Vrai jeton porteur (`asTenant`), les deux types d'école. Aucun id codé en
 * dur : une cible absente est prise au-delà des ids créés.
 */
final class RattacherUnFichierSuitLeDroitDEcrireTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const CIBLES = ['lesson', 'forum_topic', 'forum_post'];

    private const TABLES = ['lesson' => 'lessons', 'forum_topic' => 'forum_topics', 'forum_post' => 'forum_posts'];

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => ['klassci'];
        yield 'ecole autonome' => ['standalone'];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function voletsEtCibles(): iterable
    {
        foreach (self::volets() as $nom => [$volet]) {
            foreach (self::CIBLES as $cible) {
                yield "{$nom}, {$cible}" => [$volet, $cible];
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Queue::fake();
        Storage::fake('local');
    }

    // ───────────────────────── l'auteur écrit sur sa cible

    #[DataProvider('voletsEtCibles')]
    public function test_l_auteur_rattache_un_fichier_a_sa_cible(string $volet, string $type): void
    {
        $ecole = $this->ecole($volet);
        $auteur = $this->compte($ecole, $type === 'lesson' ? 'enseignant' : 'etudiant');
        $cible = $this->cible($type, $ecole, $auteur);

        $this->rattacher($auteur, $type, (int) $cible->getKey())
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('files', [
            'user_id' => $auteur->id,
            'fileable_type' => $type,
            'fileable_id' => $cible->getKey(),
            'institution_id' => $ecole->id,
        ]);
    }

    // ───────────────────────── la leçon : son enseignant seul

    #[DataProvider('volets')]
    public function test_un_compte_qui_n_est_pas_l_enseignant_de_la_lecon_n_y_rattache_rien(string $volet): void
    {
        $ecole = $this->ecole($volet);
        $lecon = $this->cible('lesson', $ecole, $this->compte($ecole, 'enseignant'));

        foreach (['etudiant', 'enseignant', 'coordinateur', 'admin', 'superAdmin'] as $role) {
            $this->rattacher($this->compte($ecole, $role), 'lesson', (int) $lecon->getKey())
                ->assertForbidden();
        }

        $this->assertRienNEstEcrit();
    }

    // ───────────────────────── le forum : la règle de ses gardes

    #[DataProvider('volets')]
    public function test_un_non_auteur_ne_rattache_rien_a_un_sujet_ni_a_un_message(string $volet): void
    {
        $ecole = $this->ecole($volet);
        $auteur = $this->compte($ecole, 'etudiant');

        foreach (['forum_topic', 'forum_post'] as $type) {
            $cible = $this->cible($type, $ecole, $auteur);
            foreach (['etudiant', 'enseignant', 'coordinateur'] as $role) {
                $this->rattacher($this->compte($ecole, $role), $type, (int) $cible->getKey())
                    ->assertForbidden();
            }
        }

        $this->assertRienNEstEcrit();
    }

    #[DataProvider('volets')]
    public function test_l_admin_de_l_ecole_rattache_un_fichier_au_forum_qu_il_modere(string $volet): void
    {
        $ecole = $this->ecole($volet);
        $admin = $this->compte($ecole, 'admin');

        foreach (['forum_topic', 'forum_post'] as $type) {
            $cible = $this->cible($type, $ecole, $this->compte($ecole, 'etudiant'));

            $this->rattacher($admin, $type, (int) $cible->getKey())->assertCreated();

            $this->assertDatabaseHas('files', [
                'user_id' => $admin->id,
                'fileable_type' => $type,
                'fileable_id' => $cible->getKey(),
            ]);
        }
    }

    // ───────────────────────── absente ou ailleurs : une seule réponse

    #[DataProvider('voletsEtCibles')]
    public function test_une_cible_absente_ou_d_une_autre_ecole_recoit_le_refus_d_une_cible_non_ecrite(
        string $volet,
        string $type,
    ): void {
        $ecole = $this->ecole($volet);
        $compte = $this->compte($ecole, $type === 'lesson' ? 'enseignant' : 'etudiant');
        $ici = $this->cible($type, $ecole, $this->compte($ecole, $type === 'lesson' ? 'enseignant' : 'etudiant'));
        $ailleurs = $this->ecole($volet);
        $laBas = $this->cible($type, $ailleurs, $this->compte($ailleurs, $type === 'lesson' ? 'enseignant' : 'etudiant'));
        $absente = (int) DB::table(self::TABLES[$type])->max('id') + 1000;

        $nonEcrite = $this->rattacher($compte, $type, (int) $ici->getKey())->assertForbidden()->json();

        foreach ([(int) $laBas->getKey(), $absente] as $identifiant) {
            self::assertSame(
                $nonEcrite,
                $this->rattacher($compte, $type, $identifiant)->assertForbidden()->json(),
            );
        }

        $this->assertRienNEstEcrit();
    }

    /**
     * Le motif du refus sur une leçon tient au RÔLE seul : il ne dit pas si la leçon
     * visée existe, ni dans quelle école.
     */
    #[DataProvider('volets')]
    public function test_un_eleve_recoit_le_meme_refus_que_la_lecon_existe_ou_non(string $volet): void
    {
        $ecole = $this->ecole($volet);
        $eleve = $this->compte($ecole, 'etudiant');
        $ailleurs = $this->ecole($volet);
        $laBas = $this->cible('lesson', $ailleurs, $this->compte($ailleurs, 'enseignant'));
        $ici = $this->cible('lesson', $ecole, $this->compte($ecole, 'enseignant'));
        $absente = (int) DB::table('lessons')->max('id') + 1000;

        $corps = array_map(
            fn (int $identifiant): mixed => $this->rattacher($eleve, 'lesson', $identifiant)
                ->assertForbidden()
                ->assertJsonPath('reason', 'lesson_content_teacher_only')
                ->json(),
            [$absente, (int) $laBas->getKey(), (int) $ici->getKey()],
        );

        self::assertSame($corps[0], $corps[1]);
        self::assertSame($corps[0], $corps[2]);
        $this->assertRienNEstEcrit();
    }

    // ───────────────────────── sans cible, et paire partielle

    #[DataProvider('volets')]
    public function test_sans_cible_le_fichier_personnel_est_inchange(string $volet): void
    {
        $ecole = $this->ecole($volet);
        $eleve = $this->compte($ecole, 'etudiant');

        $this->asTenant($eleve)
            ->post('/api/files/upload', ['file' => self::fichier()], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertDatabaseHas('files', [
            'user_id' => $eleve->id,
            'fileable_type' => null,
            'fileable_id' => null,
        ]);
    }

    #[DataProvider('volets')]
    public function test_une_paire_partielle_n_est_pas_verifiable(string $volet): void
    {
        $ecole = $this->ecole($volet);
        $auteur = $this->compte($ecole, 'enseignant');
        $lecon = $this->cible('lesson', $ecole, $auteur);

        $this->envoyer($auteur, ['fileable_type' => 'lesson'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fileable_id');

        $this->envoyer($auteur, ['fileable_id' => $lecon->getKey()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fileable_type');

        $this->assertRienNEstEcrit();
    }

    private function rattacher(User $compte, string $type, int $identifiant): TestResponse
    {
        return $this->envoyer($compte, ['fileable_type' => $type, 'fileable_id' => $identifiant]);
    }

    /** @param  array<string, mixed>  $cible */
    private function envoyer(User $compte, array $cible): TestResponse
    {
        return $this->asTenant($compte)->post(
            '/api/files/upload',
            ['file' => self::fichier(), 'is_public' => true, ...$cible],
            ['Accept' => 'application/json'],
        );
    }

    private function assertRienNEstEcrit(): void
    {
        self::assertSame(0, DB::table('files')->count());
        self::assertSame([], Storage::disk('local')->allFiles('uploads'));
    }

    private function ecole(string $volet): Institution
    {
        return $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
    }

    private function compte(Institution $ecole, string $role): User
    {
        return User::factory()->for($ecole)->create(['role' => $role, 'klassci_token' => null]);
    }

    private function cible(string $type, Institution $ecole, User $auteur): Model
    {
        return match ($type) {
            'lesson' => Lesson::factory()->for($ecole)->forTeacher($auteur)->create(),
            'forum_topic' => ForumTopic::factory()->for($ecole)->create(['user_id' => $auteur->id]),
            'forum_post' => ForumPost::factory()->create([
                'topic_id' => ForumTopic::factory()->for($ecole)->create()->id,
                'user_id' => $auteur->id,
                'institution_id' => $ecole->id,
            ]),
        };
    }

    private static function fichier(): UploadedFile
    {
        return UploadedFile::fake()->create('support.pdf', 64, 'application/pdf');
    }
}
