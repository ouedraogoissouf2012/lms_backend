<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Enrollment\ClasseEnrolmentCodeService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #885 — un apprenant qui a DÉJÀ un compte rejoint une classe avec son code.
 *
 * La porte anonyme (#846) refuse tout compte existant, et à raison : une
 * adresse n'y prouve rien. Elle renvoie donc l'apprenant ici — « Connectez-vous
 * pour rejoindre cette classe ». Cette porte-ci est la même troisième porte
 * d'ADR-803-03, authentifiée : même code, même résolution, même service.
 *
 * ## Ce que ce fichier garde AVANT tout
 *
 * **Qu'un code ne réactive jamais une adhésion que l'établissement a close.**
 * Un apprenant suspendu connaît le code de sa classe ; s'il suffisait de le
 * ressaisir, la suspension ne vaudrait rien. Les tests vérifient le statut
 * INCHANGÉ en base, pas seulement le code HTTP.
 *
 * ## Harnais
 *
 * Jeton Bearer RÉEL, jamais `Sanctum::actingAs()` : sans jeton,
 * `ResolveInstitution` ne résout aucun établissement, et le test de l'autre
 * école serait vert sans rien prouver. Une seule identité par test, sauf là où
 * `forgetGuards()` est appelé : le garde mémoïse le premier utilisateur.
 *
 * @see docs/adr/2026-09-15-803-03-trois-portes-un-service.md
 */
final class RejoindreParCodeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/me/inscriptions';

    /** Clé du plafond d'échecs d'un établissement, posée par `RejoindreParCodeService`. */
    private const ECHECS = 'rejoindre-echecs|institution:';

    /**
     * Sur la jambe Redis de la CI, le cache survit au test ; sous SQLite, les
     * id sont réattribués au test suivant. Sans ce nettoyage, un seau rempli
     * ici refuserait un apprenant ou une école d'un autre test.
     * `RateLimiter::clear('rejoindre-classe')` n'y suffirait pas : les seaux
     * sont rangés sous une clé par compte, que seul le limiteur sait former.
     */
    protected function tearDown(): void
    {
        $limiteur = RateLimiter::limiter('rejoindre-classe');

        foreach (User::query()->withoutGlobalScopes()->get() as $compte) {
            $requete = request()->duplicate();
            $requete->setUserResolver(static fn () => $compte);

            foreach ((array) ($limiteur ? $limiteur($requete) : []) as $borne) {
                // Même forme que `ThrottleRequests:134`.
                RateLimiter::clear(md5('rejoindre-classe'.$borne->key));
            }
        }

        foreach (Institution::query()->pluck('id') as $id) {
            RateLimiter::clear(self::ECHECS.$id);
        }

        parent::tearDown();
    }

    // ───────────────────────── le parcours nominal

    public function test_un_apprenant_connecte_rejoint_la_classe_avec_son_code(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $apprenant = $this->apprenant($ecole);
        $this->travelTo('2026-09-25 10:00:00');

        $this->withToken($this->jeton($apprenant))
            ->postJson(self::URL, ['code' => $code])
            ->assertStatus(201)
            ->assertJsonPath('data.classe.id', $classe->getKey());

        $ligne = $this->ligne($classe, $apprenant);

        self::assertNotNull($ligne, 'Aucune inscription écrite.');
        self::assertSame('actif', $ligne->statut);
        // ADR-711-02 : `date_inscription` est OBLIGATOIRE à l'écriture locale.
        self::assertSame('2026-09-25', substr((string) $ligne->date_inscription, 0, 10));
        // La ligne porte son établissement, comme celles du synchroniseur.
        self::assertSame($ecole->getKey(), (int) $ligne->institution_id);
    }

    public function test_le_code_est_insensible_a_la_casse_et_aux_espaces(): void
    {
        [$ecole, $code] = $this->classeAvecCode();

        $this->withToken($this->jeton($this->apprenant($ecole)))
            ->postJson(self::URL, ['code' => '  '.strtolower($code).' '])
            ->assertStatus(201);
    }

    public function test_rejoindre_une_seconde_fois_ne_reecrit_rien(): void
    {
        // Un double clic, ou le lien rouvert depuis WhatsApp : ce n'est pas une
        // faute, et la date d'inscription d'origine ne doit pas bouger.
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $apprenant = $this->apprenant($ecole);
        $this->adhesion($classe, $apprenant, 'actif', '2026-01-10');

        $this->withToken($this->jeton($apprenant))
            ->postJson(self::URL, ['code' => $code])
            ->assertStatus(200)
            ->assertJsonPath('data.classe.id', $classe->getKey());

        self::assertSame(1, DB::table('classe_etudiant')->count(), 'Une seconde ligne a été créée.');
        self::assertSame('2026-01-10', substr((string) $this->ligne($classe, $apprenant)?->date_inscription, 0, 10));
    }

    // ───────────────────────── LA garde de ce fichier

    #[DataProvider('adhesionsCloses')]
    public function test_un_code_ne_reactive_JAMAIS_une_adhesion_close(string $statut): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $apprenant = $this->apprenant($ecole);
        $this->adhesion($classe, $apprenant, $statut, '2026-01-10');

        $this->withToken($this->jeton($apprenant))
            ->postJson(self::URL, ['code' => $code])
            ->assertStatus(409);

        $ligne = $this->ligne($classe, $apprenant);

        self::assertSame($statut, $ligne?->statut, "RÉACTIVATION : l'adhésion « {$statut} » a été rouverte par le code.");
        self::assertSame('2026-01-10', substr((string) $ligne?->date_inscription, 0, 10));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adhesionsCloses(): array
    {
        // Les cinq valeurs que la base accepte, moins `actif` (ADR-711-02).
        return [
            'suspendu' => ['suspendu'],
            'abandonne' => ['abandonne'],
            'inactif' => ['inactif'],
            'expire' => ['expire'],
        ];
    }

    // ───────────────────────── les autres refus

    public function test_un_code_inconnu_et_un_code_RETIRE_rendent_le_meme_refus(): void
    {
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $jeton = $this->jeton($this->apprenant($ecole));

        $inconnu = $this->withToken($jeton)->postJson(self::URL, ['code' => 'ZZZZZZ'])->assertStatus(404);

        app(TenantManager::class)->set($ecole);
        app(ClasseEnrolmentCodeService::class)->revoquer($classe->getKey());
        app(TenantManager::class)->reset();

        $retire = $this->withToken($jeton)->postJson(self::URL, ['code' => $code])->assertStatus(404);

        self::assertSame($inconnu->json('message'), $retire->json('message'));
        self::assertSame(0, DB::table('classe_etudiant')->count());
    }

    public function test_le_code_d_une_AUTRE_ecole_n_ouvre_rien_meme_en_annoncant_cette_ecole(): void
    {
        // L'établissement vient du JETON. Un en-tête `X-Institution` qui
        // désigne l'autre école ne doit rien y changer (ResolveInstitution,
        // priorité 1) — sinon un apprenant rejoindrait une classe d'une école
        // qui ne le connaît pas.
        [$autre, $code] = $this->classeAvecCode();
        $sienne = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);

        $this->withToken($this->jeton($this->apprenant($sienne)))
            ->withHeader('X-Institution', (string) $autre->slug)
            ->postJson(self::URL, ['code' => $code])
            ->assertStatus(404);

        self::assertSame(0, DB::table('classe_etudiant')->count());
    }

    public function test_un_formateur_ne_rejoint_pas_une_classe_comme_apprenant(): void
    {
        [$ecole, $code] = $this->classeAvecCode();
        $formateur = User::factory()->create(['institution_id' => $ecole->getKey(), 'role' => 'enseignant']);

        $this->withToken($this->jeton($formateur))
            ->postJson(self::URL, ['code' => $code])
            ->assertStatus(403);

        self::assertSame(0, DB::table('classe_etudiant')->count());
    }

    public function test_l_administrateur_d_ecole_ne_rejoint_pas_une_classe_comme_apprenant(): void
    {
        // `EnsureRole` laisse passer le `superAdmin` d'établissement sur toute
        // route qui n'exige pas `supradmin` : `role:etudiant` ne l'arrête pas.
        // Sans le contrôle du service, il apparaîtrait parmi les élèves.
        [$ecole, $code] = $this->classeAvecCode();
        $admin = User::factory()->create(['institution_id' => $ecole->getKey(), 'role' => 'superAdmin']);

        $this->withToken($this->jeton($admin))
            ->postJson(self::URL, ['code' => $code])
            ->assertStatus(403);

        self::assertSame(0, DB::table('classe_etudiant')->count());
    }

    public function test_un_code_emis_n_ouvre_plus_rien_une_fois_l_ecole_passee_a_KLASSCI(): void
    {
        [$ecole, $code] = $this->classeAvecCode();
        $apprenant = $this->apprenant($ecole);
        $ecole->update(['mode' => InstitutionMode::Klassci]);

        $this->withToken($this->jeton($apprenant))
            ->postJson(self::URL, ['code' => $code])
            ->assertStatus(404);

        self::assertSame(0, DB::table('classe_etudiant')->count());
    }

    public function test_sans_jeton_la_route_refuse(): void
    {
        [$ecole, $code] = $this->classeAvecCode();

        $this->withHeader('X-Institution', (string) $ecole->slug)
            ->postJson(self::URL, ['code' => $code])
            ->assertStatus(401);

        self::assertSame(0, DB::table('classe_etudiant')->count());
    }

    public function test_un_code_absent_est_refuse_avant_toute_ecriture(): void
    {
        [$ecole] = $this->classeAvecCode();

        $this->withToken($this->jeton($this->apprenant($ecole)))
            ->postJson(self::URL, [])
            ->assertStatus(422);
    }

    // ───────────────────────── le débit

    public function test_le_debit_est_compte_par_apprenant_et_non_par_adresse(): void
    {
        // Le jour de la rentrée, toute une classe rejoint depuis le wifi de
        // l'école — une seule IP. Un seau par IP refuserait le sixième élève.
        [$ecole] = $this->classeAvecCode();
        $insistant = $this->apprenant($ecole);
        $voisin = $this->apprenant($ecole, 'voisin@test.ci');

        for ($i = 0; $i < 10; $i++) {
            $this->withToken($this->jeton($insistant))->postJson(self::URL, ['code' => 'ZZZZZZ']);
        }

        $this->withToken($this->jeton($insistant))
            ->postJson(self::URL, ['code' => 'ZZZZZZ'])
            ->assertStatus(429);

        // Le garde mémoïse le premier utilisateur : sans ceci, la requête
        // suivante serait encore celle de l'insistant.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $reponse = $this->withToken($this->jeton($voisin))
            ->postJson(self::URL, ['code' => 'ZZZZZZ']);

        // Le voisin répond sur ses propres mérites — un 404, son code n'ouvrant
        // rien. Ce qui compte : il ne paie pas le budget de l'insistant.
        self::assertSame(404, $reponse->getStatusCode(), "Le voisin a payé le budget de l'insistant.");
    }

    public function test_les_echecs_d_une_ecole_sont_plafonnes_meme_repartis_sur_plusieurs_comptes(): void
    {
        // Chaque compte a son seau ; des comptes accumulés en apportent chacun
        // un de plus. Le plafond d'établissement est la borne d'ensemble.
        // Éprouver 200 échecs par le trafic demanderait 200 requêtes et vingt
        // comptes : on remplit le compteur, et l'on vérifie qu'il ferme la
        // porte — y compris à un code VALIDE, sans quoi il ne bornerait rien.
        [$ecole, $code] = $this->classeAvecCode();

        for ($i = 0; $i < 200; $i++) {
            RateLimiter::hit(self::ECHECS.$ecole->getKey(), 86_400);
        }

        $this->withToken($this->jeton($this->apprenant($ecole)))
            ->postJson(self::URL, ['code' => $code])
            ->assertStatus(429);

        self::assertSame(0, DB::table('classe_etudiant')->count());
    }

    public function test_seul_un_echec_consomme_le_plafond_de_l_ecole(): void
    {
        [$ecole, $code] = $this->classeAvecCode();
        $jeton = $this->jeton($this->apprenant($ecole));

        $this->withToken($jeton)->postJson(self::URL, ['code' => $code])->assertStatus(201);
        self::assertSame(0, RateLimiter::attempts(self::ECHECS.$ecole->getKey()), 'Un code valide a été compté comme un échec.');

        $this->withToken($jeton)->postJson(self::URL, ['code' => 'ZZZZZZ'])->assertStatus(404);
        self::assertSame(1, RateLimiter::attempts(self::ECHECS.$ecole->getKey()), 'Un code erroné n\'a pas été compté.');
    }

    // ───────────────────────── la course

    public function test_deux_envois_simultanes_se_departagent_par_la_ligne_du_gagnant(): void
    {
        // Juste avant NOTRE insertion, une écriture concurrente pose une
        // adhésion `suspendu` : l'unique `(classe_id, user_id)` refuse la nôtre.
        // La relecture doit alors trancher sur la ligne du gagnant — 409 — et
        // non laisser remonter l'exception en 500, ni écraser le statut.
        [$ecole, $code, $classe] = $this->classeAvecCode();
        $apprenant = $this->apprenant($ecole);
        $concurrente = false;

        DB::beforeExecuting(function (string $sql) use (&$concurrente, $classe, $apprenant): void {
            if ($concurrente || preg_match('/^insert into [`"]?classe_etudiant/i', $sql) !== 1) {
                return;
            }

            $concurrente = true;
            $this->adhesion($classe, $apprenant, 'suspendu', '2026-01-10');
        });

        $this->withToken($this->jeton($apprenant))
            ->postJson(self::URL, ['code' => $code])
            ->assertStatus(409);

        self::assertTrue($concurrente, 'La course n\'a pas été provoquée : ce test ne prouverait rien.');
        self::assertSame('suspendu', $this->ligne($classe, $apprenant)?->statut);
        self::assertSame(1, DB::table('classe_etudiant')->count());
    }

    // ───────────────────────── harnais

    private function jeton(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function apprenant(Institution $ecole, string $email = 'awa@test.ci'): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'email' => $email,
            'role' => 'etudiant',
        ]);
    }

    private function adhesion(Classe $classe, User $apprenant, string $statut, string $date): void
    {
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->getKey(),
            'user_id' => $apprenant->getKey(),
            'institution_id' => $classe->institution_id,
            'statut' => $statut,
            'date_inscription' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ligne(Classe $classe, User $apprenant): ?object
    {
        return DB::table('classe_etudiant')
            ->where('classe_id', $classe->getKey())
            ->where('user_id', $apprenant->getKey())
            ->first();
    }

    /**
     * @return array{0: Institution, 1: string, 2: Classe}
     */
    private function classeAvecCode(): array
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        app(TenantManager::class)->set($ecole);

        $classe = Classe::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_id' => null,
        ]);

        $code = app(ClasseEnrolmentCodeService::class)->generer($classe->getKey());

        // Le tenant est ensuite posé par le jeton, comme en production.
        app(TenantManager::class)->reset();

        return [$ecole, $code, $classe];
    }
}
