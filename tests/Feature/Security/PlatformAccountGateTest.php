<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * La porte de l'API : un compte sans établissement n'atteint que les routes de
 * GESTION marquées « plateforme ». Toute autre route lui est refusée (403), et
 * une route nouvelle naît fermée. Le supradmin plateforme est ce compte.
 *
 * Ce fichier fixe aussi la PLACE de la porte dans la pile : après
 * l'authentification (une requête sans jeton reste un 401), et sans effet sur
 * une route publique.
 *
 * Vrai jeton porteur partout : c'est le chemin réel, celui où le tenant est
 * résolu (ou non) par ResolveInstitution.
 *
 * @see app/Http/Middleware/EnsureSchoolMemberOrPlatformRoute.php
 */
final class PlatformAccountGateTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    // ── Le compte sans établissement ─────────────────────────────────────────

    /**
     * Une route de contenu par domaine : le supradmin plateforme y est refusé.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function routesNonMarquees(): iterable
    {
        yield 'leçons' => ['GET', '/api/lessons'];
        yield 'leçons, version 1' => ['GET', '/api/v1/lessons'];
        yield 'évaluations' => ['GET', '/api/evaluations'];
        yield 'quiz' => ['GET', '/api/quizzes'];
        yield 'forum' => ['GET', '/api/forum/topics'];
        yield 'fichiers' => ['GET', '/api/files'];
        yield 'recherche' => ['GET', '/api/search?query=algebre'];
        yield 'rapport des notes' => ['POST', '/api/admin/reports/grades'];
        yield 'séances' => ['GET', '/api/lms/seances/history'];
        yield 'journal d audit (décision A1 en attente)' => ['GET', '/api/admin/audit-log'];
        yield 'comptes d école (décision A8 en attente)' => ['PUT', '/api/users/1'];
        yield 'comptes récents (décision A8 en attente)' => ['GET', '/api/admin/analytics/recent-users'];
    }

    #[DataProvider('routesNonMarquees')]
    public function test_le_supradmin_est_refuse_hors_des_routes_marquees(string $methode, string $uri): void
    {
        $reponse = $this->asTenant($this->supradmin())->json($methode, $uri);

        // Le motif de l'ADR-906-01 : le front le traduit par son catalogue.
        $reponse->assertForbidden()->assertExactJson(['success' => false, 'message' => self::MESSAGE, 'reason' => 'no_institution']);
    }

    /** Un compte orphelin (sans établissement, pas supradmin) est refusé de la même façon. */
    public function test_un_compte_orphelin_est_refuse(): void
    {
        $orphelin = User::factory()->create(['institution_id' => null, 'role' => 'coordinateur']);

        $this->asTenant($orphelin)->getJson('/api/lessons')->assertForbidden();
    }

    /** Un établissement `0` n'en est pas un, comme pour ResolveInstitution : refusé. */
    public function test_un_etablissement_zero_n_en_est_pas_un(): void
    {
        $compte = User::factory()->make(['institution_id' => 0, 'role' => 'enseignant']);

        self::assertFalse($compte->isSchoolMember());
    }

    /** Un utilisateur authentifié qui n'est pas un `User` n'a pas d'établissement : refusé. */
    public function test_un_authentifie_qui_n_est_pas_un_user_est_refuse(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));

        $this->getJson('/api/lessons')->assertForbidden()->assertJsonPath('reason', 'no_institution');
    }

    /**
     * Les routes de gestion marquées restent ouvertes au supradmin : la porte ne
     * le refuse pas (le contrôle propre à la route décide ensuite).
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function routesMarquees(): iterable
    {
        yield 'écoles' => ['GET', '/api/admin/institutions'];
        yield 'demandes d école' => ['GET', '/api/admin/school-requests'];
        yield 'statistiques de notifications' => ['GET', '/api/admin/notifications/stats'];
        yield 'session' => ['GET', '/api/auth/me'];
        yield 'compte courant' => ['GET', '/api/user'];
        yield 'ses notifications' => ['GET', '/api/notifications/unread-count'];
    }

    #[DataProvider('routesMarquees')]
    public function test_le_supradmin_garde_ses_routes_de_gestion(string $methode, string $uri): void
    {
        $reponse = $this->asTenant($this->supradmin())->json($methode, $uri);

        self::assertNotSame(self::MESSAGE, $reponse->json('message'), "la porte refuse une route de gestion : $uri");
        $reponse->assertSuccessful();
    }

    // ── La place de la porte dans la pile ────────────────────────────────────

    /** Sans jeton, l'authentification parle d'abord : 401, jamais le 403 de la porte. */
    public function test_sans_jeton_l_authentification_repond_avant_la_porte(): void
    {
        $this->getJson('/api/lessons')->assertUnauthorized();
    }

    /**
     * Le refus vient AVANT la résolution du modèle de la route : le chapitre
     * d'une école et un chapitre qui n'existe pas rendent le MÊME 403, octet
     * pour octet, jamais un 404 qui dirait ce qui existe. Sans sa place dans la
     * liste de priorité, la porte passerait après `SubstituteBindings` (groupe
     * `api`).
     */
    public function test_le_refus_vient_avant_la_resolution_du_modele(): void
    {
        $ecole = Institution::factory()->create();
        $lecon = Lesson::factory()->create(['institution_id' => $ecole->id]);
        $chapitre = Chapter::factory()->create(['institution_id' => $ecole->id, 'lesson_id' => $lecon->id]);
        $supradmin = $this->supradmin();

        $existant = $this->asTenant($supradmin)->getJson("/api/chapters/{$chapitre->id}/original");
        $absent = $this->asTenant($supradmin)->getJson('/api/chapters/' . ((int) Chapter::query()->max('id') + 1) . '/original');

        foreach (['existant' => $existant, 'absent' => $absent] as $cas => $reponse) {
            self::assertSame([403, self::MESSAGE], [$reponse->status(), $reponse->json('message')], $cas);
        }
        self::assertSame($existant->getContent(), $absent->getContent(), 'le corps ne dit pas ce qui existe');
    }

    /** Une route publique reste atteignable sans compte : la porte n'y change rien. */
    public function test_une_route_publique_reste_atteignable_sans_compte(): void
    {
        $connexion = $this->postJson('/api/auth/login', ['email' => 'inconnu@exemple.ci', 'password' => 'mauvais']);
        $inscription = $this->postJson('/api/inscriptions', []);

        self::assertNotSame(self::MESSAGE, $connexion->json('message'));
        self::assertNotContains($connexion->status(), [401, 403]);
        self::assertNotSame(self::MESSAGE, $inscription->json('message'));
        self::assertNotSame(403, $inscription->status());
    }

    // ── Le membre d'une école ────────────────────────────────────────────────

    /** @return iterable<string, array{0: bool}> */
    public static function volets(): iterable
    {
        yield 'école reliée à KLASSCI' => [false];
        yield 'école autonome' => [true];
    }

    /** Un membre d'une école passe la porte, dans les deux volets. */
    #[DataProvider('volets')]
    public function test_un_membre_d_une_ecole_passe_la_porte(bool $autonome): void
    {
        $ecole = $autonome ? Institution::factory()->standalone()->create() : Institution::factory()->create();
        $enseignant = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'enseignant']);

        $this->asTenant($enseignant)->getJson('/api/lessons')->assertOk();
    }

    private const MESSAGE = "Cet espace est réservé aux comptes d'un établissement.";

    private function supradmin(): User
    {
        return User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);
    }
}
