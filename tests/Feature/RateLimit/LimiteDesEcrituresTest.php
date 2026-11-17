<?php

declare(strict_types=1);

namespace Tests\Feature\RateLimit;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1196 — un seau de débit par famille d'écriture, et par nom.
 *
 * Un `throttle:N,M` anonyme a pour clé l'id du COMPTE seul (ou `domaine|IP` sans
 * compte), sans la route ni N (`ThrottleRequests::resolveRequestSignature`) : 61
 * routes partageaient un compteur. Un élève qui avançait dans une leçon (300/min)
 * consommait le budget de ses soumissions ; un enseignant qui enregistrait leçon,
 * chapitres et quiz épuisait celui de sa séance (l'E2E 44076995960) ; derrière le
 * NAT d'une école, des connexions ratées fermaient « mot de passe oublié ».
 *
 * Désormais : un limiteur `ecriture`, posé une fois sur le groupe `api`, clé
 * `ecriture|famille|user_id` ; des limiteurs NOMMÉS pour les lectures à limite
 * propre et les routes sans compte ; aucun `throttle:N,M` dans `routes/`.
 *
 * La limite ne dépend pas du mode de l'école : les deux volets sont figés.
 */
final class LimiteDesEcrituresTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    public function test_aucune_route_ne_porte_de_limite_anonyme(): void
    {
        $ecarts = [];
        $fichiers = 0;

        foreach (Finder::create()->files()->in(base_path('routes'))->name('*.php') as $fichier) {
            $fichiers++;
            foreach (preg_split('/\R/', $fichier->getContents()) ?: [] as $numero => $ligne) {
                if (preg_match('/throttle:\d+,\d+/', $ligne) === 1) {
                    $ecarts[] = 'routes/'.$fichier->getRelativePathname().':'.($numero + 1).'  '.trim($ligne);
                }
            }
        }

        self::assertGreaterThan(5, $fichiers);
        self::assertSame([], $ecarts, "Une limite anonyme partage le seau de TOUT le compte (#1196) :\n".implode("\n", $ecarts));
    }

    public function test_toute_ecriture_authentifiee_passe_par_la_limite(): void
    {
        $routeur = app('router');
        $inspectees = 0;
        $sans = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'api/__test/')
                || array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === []) {
                continue;
            }
            $middlewares = $routeur->gatherRouteMiddleware($route);
            if (preg_grep('/Authenticate:sanctum$/', $middlewares) === []) {
                continue;
            }
            $inspectees++;
            if (preg_grep('/ThrottleRequests(WithRedis)?:ecriture$/', $middlewares) === []) {
                $sans[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        self::assertGreaterThan(100, $inspectees, 'La garde n\'a presque rien inspecté : elle ne prouverait rien.');
        self::assertSame([], $sans, "Écriture(s) authentifiée(s) sans le limiteur `ecriture` :\n  - ".implode("\n  - ", $sans));
    }

    /**
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function familles(): iterable
    {
        yield 'progression' => ['POST', '/api/lessons/1/progress', 'participation', 300];
        yield 'chapitre terminé' => ['POST', '/api/chapters/1/complete', 'participation', 300];
        yield 'soumettre une évaluation' => ['POST', '/api/evaluations/1/submit', 'participation', 300];
        yield 'rejoindre une visio' => ['POST', '/api/lms/seances/1/join', 'participation', 300];
        yield 'modifier une leçon' => ['PUT', '/api/lessons/1', 'contenu', 100];
        yield 'modifier une séance' => ['PUT', '/api/lms/seances/1', 'contenu', 100];
        yield 'déposer un fichier' => ['POST', '/api/files/upload', 'contenu', 100];
        yield 'une écriture non classée' => ['POST', '/api/lms/attendances/from-video-session', 'contenu', 100];
        yield 'marquer ses notifications lues' => ['POST', '/api/notifications/mark-all-as-read', 'participation', 300];
        yield 'forum' => ['POST', '/api/forum/topics', 'forum', 100];
        yield 'créer un compte' => ['POST', '/api/users', 'gestion', 60];
        yield 'rapport' => ['POST', '/api/admin/reports/grades', 'gestion', 60];
    }

    #[DataProvider('familles')]
    public function test_chaque_famille_a_son_seuil_et_son_seau(string $methode, string $chemin, string $famille, int $seuil): void
    {
        $compte = $this->compte(InstitutionMode::Standalone, 'enseignant');

        $limite = $this->limite($methode, $chemin, $compte);

        self::assertInstanceOf(Limit::class, $limite);
        self::assertNotInstanceOf(Unlimited::class, $limite);
        self::assertSame($seuil, $limite->maxAttempts, "$methode $chemin");
        self::assertSame("ecriture|{$famille}|{$compte->id}", $limite->key);
    }

    public function test_une_lecture_un_invite_et_le_supradmin_ne_sont_pas_comptes(): void
    {
        $compte = $this->compte(InstitutionMode::Standalone, 'enseignant');
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        self::assertInstanceOf(Unlimited::class, $this->limite('GET', '/api/lessons/1', $compte));
        self::assertInstanceOf(Unlimited::class, $this->limite('PUT', '/api/lessons/1', null));
        self::assertInstanceOf(Unlimited::class, $this->limite('PUT', '/api/lessons/1', $supradmin));
    }

    /**
     * Le cas de l'issue, ailleurs : 31 progressions de l'élève (seuil 300) ne
     * ferment plus la modification d'une leçon de l'enseignant (aujourd'hui 30, le
     * même seau). Un même compte, ici l'enseignant, pour que le partage se voie.
     */
    #[DataProvider('volets')]
    public function test_une_famille_ne_consomme_pas_le_budget_d_une_autre(InstitutionMode $mode): void
    {
        $enseignant = $this->compte($mode, 'enseignant');
        $lecon = $this->leconDe($enseignant);

        for ($i = 0; $i < 31; $i++) {
            self::assertNotSame(429, $this->asTenant($enseignant)->postJson("/api/lessons/{$lecon->id}/progress", ['progress_percentage' => 10])->getStatusCode());
        }

        $reponse = $this->asTenant($enseignant)->putJson("/api/lessons/{$lecon->id}", ['title' => 'Après la progression']);

        self::assertNotSame(429, $reponse->getStatusCode(), (string) $reponse->getContent());
    }

    /** Au-delà du seuil de sa famille, 429 avec `Retry-After` ; les autres familles restent ouvertes. */
    #[DataProvider('volets')]
    public function test_au_dela_du_seuil_la_famille_recoit_un_429(InstitutionMode $mode): void
    {
        $gestion = $this->compte($mode, 'coordinateur');

        for ($i = 0; $i < 60; $i++) {
            // Un corps vide : 422, mais compté (la limite passe avant le contrôleur).
            self::assertNotSame(429, $this->asTenant($gestion)->postJson('/api/users', [])->getStatusCode(), "écriture $i");
        }

        $this->asTenant($gestion)->postJson('/api/users', [])->assertStatus(429)->assertHeader('Retry-After');
        self::assertNotSame(429, $this->asTenant($gestion)->postJson('/api/forum/topics', [])->getStatusCode(), 'Le forum a son seau.');
    }

    /**
     * Sans compte, la clé était `domaine|IP` pour TOUTES les routes limitées : dix
     * connexions ratées fermaient « mot de passe oublié » (seuil 5) à toute l'IP —
     * à toute une école derrière un NAT.
     */
    public function test_sans_compte_chaque_route_a_son_seau(): void
    {
        for ($i = 0; $i < 10; $i++) {
            self::assertNotSame(429, $this->postJson('/api/auth/login', ['username' => 'personne', 'password' => 'faux'])->getStatusCode());
        }

        $reponse = $this->postJson('/api/auth/forgot-password', ['email' => 'personne@ecole.test']);

        self::assertNotSame(429, $reponse->getStatusCode(), (string) $reponse->getContent());
    }

    /**
     * @return array<string, array{InstitutionMode}>
     */
    public static function volets(): array
    {
        return [
            'école KLASSCI' => [InstitutionMode::Klassci],
            'école autonome' => [InstitutionMode::Standalone],
        ];
    }

    private function limite(string $methode, string $chemin, ?User $compte): mixed
    {
        $limiteur = RateLimiter::limiter('ecriture');
        self::assertNotNull($limiteur, 'Aucun limiteur nommé `ecriture` n\'est déclaré.');

        $requete = Request::create($chemin, $methode);
        $requete->setUserResolver(static fn (): ?User => $compte);

        return $limiteur($requete);
    }

    private function leconDe(User $enseignant): Lesson
    {
        $classe = Classe::factory()->create(['institution_id' => $enseignant->institution_id]);

        return Lesson::factory()->published()->create([
            'institution_id' => $enseignant->institution_id,
            'classe_id' => $classe->id,
            'enseignant_id' => $enseignant->id,
        ]);
    }

    private function compte(InstitutionMode $mode, string $role): User
    {
        $factory = Institution::factory();
        $ecole = ($mode === InstitutionMode::Standalone ? $factory->standalone() : $factory)->create(['mode' => $mode]);

        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role]);
    }
}
