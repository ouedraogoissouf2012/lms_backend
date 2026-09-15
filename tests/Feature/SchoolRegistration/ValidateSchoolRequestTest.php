<?php

declare(strict_types=1);

namespace Tests\Feature\SchoolRegistration;

use App\Models\ActivationToken;
use App\Models\Institution;
use App\Models\SchoolRegistrationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * #803 / #793 — la validation d'une demande, et l'accès qu'elle ouvre.
 *
 * ## Ce que ce fichier garde
 *
 * ADR-803-02 exige UNE transaction : institution + compte propriétaire + demande
 * tranchée. Si l'une échoue, aucune n'est écrite.
 *
 * Ce n'est pas du confort. Une validation à moitié faite reproduit exactement le
 * défaut #793 : une école qui existe en base et où personne ne peut entrer.
 *
 * L'atomicité est prouvée DEUX fois, et il faut les deux :
 *
 *   - par une panne ARTIFICIELLE (écouteur `creating` qui lève), seule façon
 *     déterministe de frapper au milieu de la transaction ;
 *   - par la panne RÉALISTE — un slug déjà pris, donc une violation de
 *     contrainte. Sans elle, on ne prouverait le rollback que sur un cas que la
 *     production ne produira jamais.
 *
 * ## Aucun mot de passe ne circule
 *
 * `app/` ne contient aucun `Mail::` ni `->notify()`, et `config/mail.php:17`
 * vaut `log` : le produit ne peut transmettre aucun secret. La validation émet
 * donc un lien d'activation à usage unique, affiché une fois au supradmin, que
 * le titulaire consomme pour poser SON mot de passe.
 *
 * L'usage unique se prouve en base, pas par l'expiration : une URL signée
 * expirante reste rejouable pendant toute sa fenêtre.
 *
 * ## Pourquoi un VRAI jeton Bearer, jamais `Sanctum::actingAs`
 *
 * `actingAs` n'émet aucun jeton : `ResolveInstitution` ne résout alors aucun
 * tenant, le scope multi-tenant s'efface, et une garde d'accès paraît passer
 * alors qu'elle n'a rien gardé. Un test de permission écrit ainsi est un faux
 * négatif — même patron que `tests/Feature/Admin/AdminUserListTenantIsolationTest`.
 *
 * @see docs/adr/2026-09-15-803-02-validation-atomique.md
 */
final class ValidateSchoolRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function entete(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('school-req-803')->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    private function enteteSupradmin(): array
    {
        return $this->entete(User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
            'email' => 'sup'.uniqid().'@plateforme.test',
        ]));
    }

    private function demandeEnAttente(): SchoolRegistrationRequest
    {
        return SchoolRegistrationRequest::factory()->create([
            'email_demandeur' => 'awa@cabinet-kf.ci',
            'nom_demandeur' => 'Awa Kouassi',
            'nom_ecole' => 'Cabinet Kouassi Formation',
            'slug_souhaite' => 'cabinet-kouassi',
        ]);
    }

    private function routeValidation(SchoolRegistrationRequest $d): string
    {
        return "/api/admin/school-requests/{$d->getKey()}/validate";
    }

    private function jetonDepuis(string $url): string
    {
        $position = mb_strrpos($url, '/');

        return $position === false ? '' : (string) mb_substr($url, $position + 1);
    }

    // ───────────────────────────────── atomicité

    public function test_une_validation_qui_echoue_a_mi_chemin_ne_laisse_rien(): void
    {
        $entete = $this->enteteSupradmin();
        $demande = $this->demandeEnAttente();

        // L'étape 2 tombe : le compte propriétaire ne peut pas être créé.
        User::creating(static function (User $u): void {
            if ($u->role === 'superAdmin') {
                throw new \RuntimeException('panne simulee a l etape 2');
            }
        });

        $this->withHeaders($entete)->postJson($this->routeValidation($demande))->assertStatus(500);

        User::flushEventListeners();

        // Aucune des trois écritures ne doit subsister.
        $this->assertDatabaseMissing('institutions', ['slug' => 'cabinet-kouassi']);
        $this->assertDatabaseHas('school_registration_requests', [
            'id' => $demande->getKey(),
            'statut' => 'en_attente',
            'institution_id' => null,
        ]);
        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('role', 'superAdmin')->count());
    }

    // ───────────────────────────────── le cas nominal

    public function test_valider_cree_l_institution_son_proprietaire_et_un_lien(): void
    {
        $entete = $this->enteteSupradmin();
        $demande = $this->demandeEnAttente();

        $reponse = $this->withHeaders($entete)
            ->postJson($this->routeValidation($demande))
            ->assertStatus(200);

        // L'institution naît AUTONOME : c'est le discriminant du monde local.
        $this->assertDatabaseHas('institutions', [
            'slug' => 'cabinet-kouassi',
            'name' => 'Cabinet Kouassi Formation',
            'klassci_api_url' => null,
        ]);

        $institution = Institution::query()->withoutGlobalScopes()
            ->where('slug', 'cabinet-kouassi')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'email' => 'awa@cabinet-kf.ci',
            'role' => 'superAdmin',
            'institution_id' => $institution->getKey(),
        ]);

        $this->assertDatabaseHas('school_registration_requests', [
            'id' => $demande->getKey(),
            'statut' => 'validee',
            'institution_id' => $institution->getKey(),
        ]);

        $reponse->assertJsonPath('data.institution.slug', 'cabinet-kouassi');
        $this->assertIsString($reponse->json('data.activation_url'));
    }

    public function test_le_supradmin_peut_arbitrer_le_slug_en_cas_de_collision(): void
    {
        $entete = $this->enteteSupradmin();
        Institution::factory()->create(['slug' => 'cabinet-kouassi']);
        $demande = $this->demandeEnAttente();

        $this->withHeaders($entete)
            ->postJson($this->routeValidation($demande), ['slug' => 'cabinet-kouassi-abidjan'])
            ->assertStatus(200);

        $this->assertDatabaseHas('institutions', ['slug' => 'cabinet-kouassi-abidjan']);
    }

    public function test_le_jeton_n_est_jamais_stocke_en_clair(): void
    {
        $entete = $this->enteteSupradmin();
        $demande = $this->demandeEnAttente();

        $url = (string) $this->withHeaders($entete)
            ->postJson($this->routeValidation($demande))
            ->json('data.activation_url');

        $jeton = $this->jetonDepuis($url);

        $this->assertNotSame('', $jeton);
        $this->assertDatabaseMissing('activation_tokens', ['token_hash' => $jeton]);
    }

    /**
     * La panne RÉALISTE : le slug est déjà pris, donc violation de contrainte.
     *
     * Le test par écouteur prouve le rollback sur une exception applicative ;
     * celui-ci le prouve sur ce que la production produira vraiment.
     */
    public function test_un_slug_deja_pris_ne_laisse_rien_derriere_lui(): void
    {
        $entete = $this->enteteSupradmin();
        Institution::factory()->create(['slug' => 'cabinet-kouassi']);
        $demande = $this->demandeEnAttente();

        $avant = Institution::query()->withoutGlobalScopes()->count();

        // Aucun slug de substitution : la collision doit frapper l'écriture.
        $this->withHeaders($entete)->postJson($this->routeValidation($demande));

        $this->assertSame($avant, Institution::query()->withoutGlobalScopes()->count());
        $this->assertDatabaseHas('school_registration_requests', [
            'id' => $demande->getKey(),
            'statut' => 'en_attente',
            'institution_id' => null,
        ]);
        $this->assertSame(0, User::query()->withoutGlobalScopes()->where('role', 'superAdmin')->count());
    }

    /**
     * Le lien doit mener à l'application WEB, pas à l'API.
     *
     * Un lien bâti sur `app.url` serait syntaxiquement correct et
     * fonctionnellement mort : l'hôte de l'API n'a aucune page d'activation. Le
     * défaut n'apparaîtrait que chez le destinataire, hors de portée de tout
     * journal, sous la forme d'un compte qu'on ne peut plus ouvrir.
     */
    public function test_le_lien_pointe_sur_l_application_web_et_non_sur_l_api(): void
    {
        $entete = $this->enteteSupradmin();
        $demande = $this->demandeEnAttente();

        $url = (string) $this->withHeaders($entete)
            ->postJson($this->routeValidation($demande))
            ->json('data.activation_url');

        $this->assertStringStartsWith('https://lms.test/activation/', $url);
        $this->assertStringNotContainsString((string) config('app.url'), $url);
    }

    public function test_sans_adresse_du_front_la_validation_n_ecrit_rien(): void
    {
        config(['activation.url_front' => null]);

        $entete = $this->enteteSupradmin();
        $demande = $this->demandeEnAttente();

        $this->withHeaders($entete)->postJson($this->routeValidation($demande))->assertStatus(503);

        // Fail-closed : le refus survient DANS la transaction, rien ne subsiste.
        $this->assertDatabaseMissing('institutions', ['slug' => 'cabinet-kouassi']);
        $this->assertDatabaseHas('school_registration_requests', [
            'id' => $demande->getKey(),
            'statut' => 'en_attente',
        ]);
    }

    public function test_un_jeton_perime_est_refuse(): void
    {
        $entete = $this->enteteSupradmin();
        $demande = $this->demandeEnAttente();
        $url = (string) $this->withHeaders($entete)
            ->postJson($this->routeValidation($demande))
            ->json('data.activation_url');

        // On fait vieillir le jeton au-delà de sa fenêtre.
        ActivationToken::query()->update(['expires_at' => now()->subDay()]);

        $this->postJson('/api/activation', [
            'token' => $this->jetonDepuis($url),
            'password' => 'MotDePasseSolide#2026',
            'password_confirmation' => 'MotDePasseSolide#2026',
        ])->assertStatus(410);
    }

    // ───────────────────────────────── refus

    public function test_refuser_exige_un_motif_et_ne_cree_rien(): void
    {
        $entete = $this->enteteSupradmin();
        $demande = $this->demandeEnAttente();
        $route = "/api/admin/school-requests/{$demande->getKey()}/refuse";

        $this->withHeaders($entete)->postJson($route, [])->assertStatus(422);

        $this->withHeaders($entete)
            ->postJson($route, ['motif_refus' => 'Dossier incomplet : aucune adresse verifiable.'])
            ->assertStatus(200);

        $this->assertDatabaseHas('school_registration_requests', [
            'id' => $demande->getKey(),
            'statut' => 'refusee',
            'institution_id' => null,
        ]);
        $this->assertSame(0, Institution::query()->withoutGlobalScopes()->count());
    }

    public function test_une_demande_deja_tranchee_ne_se_revalide_pas(): void
    {
        $entete = $this->enteteSupradmin();
        $demande = SchoolRegistrationRequest::factory()->refusee()->create();

        $this->withHeaders($entete)->postJson($this->routeValidation($demande))->assertStatus(409);
    }

    // ───────────────────────────────── qui a le droit

    public function test_un_superadmin_d_institution_ne_peut_pas_valider(): void
    {
        $institution = Institution::factory()->create();
        $entete = $this->entete(User::factory()->create([
            'institution_id' => $institution->getKey(),
            'role' => 'superAdmin',
        ]));

        $this->withHeaders($entete)
            ->postJson($this->routeValidation($this->demandeEnAttente()))
            ->assertStatus(403);
    }

    public function test_un_anonyme_ne_peut_pas_valider(): void
    {
        $this->postJson($this->routeValidation($this->demandeEnAttente()))->assertStatus(401);
    }

    // ───────────────────────────────── l'activation, bout en bout

    public function test_le_titulaire_pose_son_mot_de_passe(): void
    {
        $entete = $this->enteteSupradmin();
        $demande = $this->demandeEnAttente();
        $url = (string) $this->withHeaders($entete)
            ->postJson($this->routeValidation($demande))
            ->json('data.activation_url');

        $this->postJson('/api/activation', [
            'token' => $this->jetonDepuis($url),
            'password' => 'MotDePasseSolide#2026',
            'password_confirmation' => 'MotDePasseSolide#2026',
        ])->assertStatus(200);

        $proprietaire = User::query()->withoutGlobalScopes()
            ->where('email', 'awa@cabinet-kf.ci')->firstOrFail();

        $this->assertTrue(Hash::check('MotDePasseSolide#2026', (string) $proprietaire->password));
    }

    public function test_le_lien_ne_sert_qu_une_fois(): void
    {
        $entete = $this->enteteSupradmin();
        $demande = $this->demandeEnAttente();
        $url = (string) $this->withHeaders($entete)
            ->postJson($this->routeValidation($demande))
            ->json('data.activation_url');

        $charge = [
            'token' => $this->jetonDepuis($url),
            'password' => 'MotDePasseSolide#2026',
            'password_confirmation' => 'MotDePasseSolide#2026',
        ];

        $this->postJson('/api/activation', $charge)->assertStatus(200);

        // Deuxième usage : refusé, même dans la fenêtre de validité. C'est ce
        // qu'une simple expiration ne garantit PAS.
        $this->postJson('/api/activation', $charge)->assertStatus(410);
    }

    public function test_un_jeton_inconnu_est_refuse(): void
    {
        $this->postJson('/api/activation', [
            'token' => 'jeton-qui-n-existe-pas',
            'password' => 'MotDePasseSolide#2026',
            'password_confirmation' => 'MotDePasseSolide#2026',
        ])->assertStatus(410);
    }
}
