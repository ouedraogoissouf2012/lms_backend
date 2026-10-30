<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Http\Middleware\EnsureSchoolMemberOrPlatformRoute;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #941 — un rôle non reconnu ne reçoit plus la vue du personnel sur les leçons.
 *
 * `LessonListService` décidait la vue par `isStudent()`, fermée seulement sur
 * un rôle RECONNU : `Etudiant`, `eleve`… tombaient dans la vue du personnel
 * (toutes les leçons de l'établissement, brouillons compris), restriction de
 * classe #482 contournée. La vue du personnel exige désormais `isStaff()` ;
 * tout autre compte reçoit la vue apprenant : publiées, de SES classes.
 *
 * Les deux volets, sans lire le mode : école klassci (inscription par le cache
 * `user_classes`, où les rôles arrivent bruts de KLASSCI) et école standalone
 * (pivot, rôles validés à l'écriture — une variante n'y vient que d'une ligne
 * ancienne). Vrai jeton porteur : le scope tenant filtre.
 *
 * @see app/Services/Lesson/LessonListService.php
 */
final class LessonRoleNonReconnuTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $ecole;

    private Classe $classeA;

    /** @var list<int> */
    private array $publieesA;

    private Lesson $brouillonA;

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function apprenants(): iterable
    {
        foreach (['klassci', 'standalone'] as $volet) {
            // Variantes de casse de rôles à privilège comprises : aucune ne gagne
            // la vue du personnel. Rôle vide : `users.role` est NOT NULL (défaut
            // `student`), un rôle NULL n'existe pas en base ; la chaîne vide
            // est le rôle absent que la base admet.
            foreach (['Etudiant', 'eleve', 'ETUDIANT', 'SuperAdmin', 'Supradmin', 'ADMIN', 'Enseignant', ''] as $variante) {
                yield "rôle non reconnu « $variante », école $volet" => [$volet, $variante];
            }
            // Témoins : les rôles RECONNUS d'apprenant gardent exactement la même vue.
            yield "témoin « etudiant », école $volet" => [$volet, 'etudiant'];
            yield "témoin alias « student », école $volet" => [$volet, 'student'];
        }
    }

    #[DataProvider('apprenants')]
    public function test_un_compte_hors_personnel_recoit_la_vue_apprenant(string $volet, string $role): void
    {
        $compte = $this->ecoleAvecDeuxClasses($volet, $role);
        $id = fn (array $donnees): array => collect($donnees)->pluck('id')->sort()->values()->all();

        // my-courses (:151) : seulement sa classe.
        $mesCours = $this->asTenant($compte)->getJson('/api/lessons/my-courses?per_page=100')->assertOk();
        self::assertSame($this->publieesA, $id($mesCours->json('data')), 'my-courses');

        // GET /lessons (:80) : publiées de sa classe, et le filtre `status` ignoré.
        $liste = $this->asTenant($compte)->getJson('/api/lessons?per_page=100')->assertOk();
        self::assertSame($this->publieesA, $id($liste->json('data.data')), 'GET /lessons');
        $brouillons = $this->asTenant($compte)->getJson('/api/lessons?per_page=100&status=draft')->assertOk();
        self::assertNotContains($this->brouillonA->id, $id($brouillons->json('data.data')), 'GET /lessons?status=draft');

        // GET /lessons (:92) : la progression de l'apprenant est jointe.
        self::assertArrayHasKey('user_progress', $liste->json('data.data.0'), 'progression de l\'apprenant');

        // GET /lessons/{id} (:216) : une leçon non publiée est refusée.
        $this->asTenant($compte)->getJson("/api/lessons/{$this->brouillonA->id}")->assertStatus(403);
    }

    /** @return iterable<string, array{0: string}> */
    public static function personnel(): iterable
    {
        foreach (['enseignant', 'teacher', 'coordinateur', 'admin', 'superAdmin'] as $role) {
            yield "personnel « $role »" => [$role];
        }
    }

    /**
     * Les rôles reconnus du personnel gardent leur vue : toutes les leçons de
     * l'établissement, brouillons compris, et le filtre `status`. Rien de ce
     * qui porte #511 / #102 n'a changé (`Role::tryFromString`, `EnsureRole`,
     * `isPlatformSupradmin`).
     */
    #[DataProvider('personnel')]
    public function test_le_personnel_reconnu_garde_sa_vue(string $role): void
    {
        $compte = $this->ecoleAvecDeuxClasses('klassci', $role, inscrire: false);

        $liste = $this->asTenant($compte)->getJson('/api/lessons?per_page=100')->assertOk();
        self::assertSame(6, $liste->json('data.total'), 'les 5 publiées et le brouillon');
        $brouillons = $this->asTenant($compte)->getJson('/api/lessons?per_page=100&status=draft')->assertOk();
        self::assertSame([$this->brouillonA->id], collect($brouillons->json('data.data'))->pluck('id')->all());
        $this->asTenant($compte)->getJson("/api/lessons/{$this->brouillonA->id}")->assertOk();
        self::assertSame(5, $this->asTenant($compte)->getJson('/api/lessons/my-courses?per_page=100')->json('meta.total'));
    }

    /** Le supradmin plateforme (institution NULL, comparaison stricte #511) garde sa vue. */
    /**
     * Le supradmin plateforme ne lit aucune leçon, brouillon ou publiée (décision
     * produit) : la porte des comptes sans établissement le refuse.
     */
    public function test_le_supradmin_plateforme_ne_lit_aucune_lecon(): void
    {
        $this->ecoleAvecDeuxClasses('klassci', 'enseignant', inscrire: false);
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $this->asTenant($supradmin)->getJson("/api/lessons/{$this->brouillonA->id}")
            ->assertForbidden()->assertJsonPath('message', EnsureSchoolMemberOrPlatformRoute::MESSAGE);
        $this->asTenant($supradmin)->getJson('/api/lessons?per_page=100')->assertForbidden()->assertJsonMissingPath('data');
    }

    private function ecoleAvecDeuxClasses(string $volet, string $role, bool $inscrire = true): User
    {
        $this->disableKlassciMiddleware();
        $standalone = $volet === 'standalone';

        $this->ecole = $standalone ? Institution::factory()->standalone()->create() : Institution::factory()->create();
        $this->classeA = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => $standalone ? null : 7001]);
        $classeB = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => $standalone ? null : 7002]);

        /** @var list<int> $ids */
        $ids = Lesson::factory()->published()->count(2)->create(['institution_id' => $this->ecole->id, 'classe_id' => $this->classeA->id])
            ->pluck('id')->sort()->values()->all();
        $this->publieesA = $ids;
        Lesson::factory()->published()->count(3)->create(['institution_id' => $this->ecole->id, 'classe_id' => $classeB->id]);
        $this->brouillonA = Lesson::factory()->create(['institution_id' => $this->ecole->id, 'classe_id' => $this->classeA->id, 'status' => 'draft']);

        $compte = User::factory()->create([
            'institution_id' => $this->ecole->id,
            'role' => $role,
            'klassci_id' => $standalone ? null : 910001,
        ]);

        if ($inscrire && $standalone) {
            $this->classeA->etudiants()->attach($compte->id, ['statut' => 'actif']);
        } elseif ($inscrire) {
            UserClass::create([
                'user_id' => $compte->id,
                'klassci_classe_id' => 7001,
                'classe_nom' => $this->classeA->libelle,
                'institution_id' => $this->ecole->id,
                'synced_at' => now(),
            ]);
        }

        return $compte;
    }
}
