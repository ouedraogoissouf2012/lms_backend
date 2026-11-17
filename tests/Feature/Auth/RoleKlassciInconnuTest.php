<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #949 — volet KLASSCI : un rôle que la table du LMS (`Role::tryFromString`) ne
 * connaît pas n'est JAMAIS écrit en base (la production n'a que des valeurs
 * canoniques, `role` comme `klassci_role`, mesuré le 17/11).
 *
 * - Compte NOUVEAU : la connexion est refusée, 403 `klassci_role_unknown`, une
 *   ligne de journal, aucune ligne `users`.
 * - Compte EXISTANT : son rôle LMS est figé (CRITICAL-05), il garde l'accès ;
 *   `klassci_role` garde sa valeur précédente, et le journal dit le rôle reçu.
 * - Rôle connu : écrit sous sa valeur canonique (`teacher` → `enseignant`) ;
 *   `supradmin` exact → `superAdmin` (#510) ; une VARIANTE de `supradmin` n'est
 *   plus promue : elle est inconnue, donc refusée.
 * - Rôle absent : `etudiant`, comme avant.
 */
final class RoleKlassciInconnuTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_URL = 'https://tenant949.klassci.test/api/lms';

    private const IDENTIFIANT = 'agent.949';

    private Institution $ecole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ecole = Institution::factory()->create([
            'slug' => 'tenant949',
            'is_active' => true,
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => self::TENANT_URL,
        ]);
    }

    public function test_un_compte_nouveau_au_role_inconnu_est_refuse_sans_aucune_ligne(): void
    {
        Log::spy();
        $this->feindreKlassci(['role' => 'directeur']);

        $this->connecter()->assertForbidden()->assertJsonPath('success', false)->assertJsonPath('reason', 'klassci_role_unknown');

        self::assertSame(0, User::withoutGlobalScopes()->withTrashed()->count(), 'Aucun compte créé.');
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $contexte = []): bool => ($contexte['klassci_role_recu'] ?? null) === 'directeur',
        );
    }

    public function test_une_variante_de_supradmin_n_est_plus_promue(): void
    {
        $this->feindreKlassci(['role' => 'Supradmin']);

        $this->connecter()->assertForbidden()->assertJsonPath('reason', 'klassci_role_unknown');

        self::assertSame(0, User::withoutGlobalScopes()->withTrashed()->count());
    }

    public function test_un_compte_existant_garde_l_acces_et_son_klassci_role(): void
    {
        Log::spy();
        $existant = User::factory()->for($this->ecole)->create([
            'role' => 'enseignant',
            'klassci_role' => 'enseignant',
            'klassci_id' => 9490,
            'email' => 'agent949@klassci.test',
        ]);
        $this->feindreKlassci(['role' => 'directeur']);

        $this->connecter()->assertOk();

        $relu = User::withoutGlobalScopes()->findOrFail($existant->id);
        self::assertSame('enseignant', $relu->role, 'Le rôle LMS est figé.');
        self::assertSame('enseignant', $relu->klassci_role, 'Une valeur hors de la table n\'est jamais écrite.');
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $contexte = []): bool => ($contexte['klassci_role_recu'] ?? null) === 'directeur',
        );
    }

    public function test_un_role_connu_est_ecrit_sous_sa_valeur_canonique(): void
    {
        $this->feindreKlassci(['role' => 'teacher']);

        $this->connecter()->assertOk();

        $cree = User::withoutGlobalScopes()->where('klassci_id', 9490)->firstOrFail();
        self::assertSame('enseignant', $cree->role);
        self::assertSame('enseignant', $cree->klassci_role);
    }

    public function test_supradmin_exact_reste_ramene_a_l_admin_d_ecole(): void
    {
        $this->feindreKlassci(['role' => 'supradmin']);

        $this->connecter()->assertOk();

        self::assertSame('superAdmin', User::withoutGlobalScopes()->where('klassci_id', 9490)->firstOrFail()->role);
    }

    public function test_un_role_absent_donne_etudiant(): void
    {
        $this->feindreKlassci([]);

        $this->connecter()->assertOk();

        $cree = User::withoutGlobalScopes()->where('klassci_id', 9490)->firstOrFail();
        self::assertSame('etudiant', $cree->role);
        self::assertSame('etudiant', $cree->klassci_role);
    }

    /**
     * KLASSCI, feint UNE fois, selon la requête (deux `Http::fake()` s'empilent et
     * le premier gagne) : il reconnaît l'identifiant, accepte le mot de passe, et
     * répond vide à toute lecture suivante.
     *
     * @param  array<string, mixed>  $attributs  ce que `data.user` ajoute (le rôle)
     */
    private function feindreKlassci(array $attributs): void
    {
        $utilisateur = ['id' => 9490, 'email' => 'agent949@klassci.test', 'name' => 'Agent 949', 'nom' => 'Agent', 'prenom' => '949'] + $attributs;

        Http::fake(static function (Request $requete) use ($utilisateur) {
            if (str_ends_with($requete->url(), '/auth/check-user')) {
                return Http::response(['success' => true, 'data' => ['found' => true]], 200);
            }
            if (str_ends_with($requete->url(), '/auth/login')) {
                return Http::response(['success' => true, 'data' => ['user' => $utilisateur, 'token' => 'jeton-949'], 'meta' => []], 200);
            }

            return Http::response(['success' => true, 'data' => []], 200);
        });
    }

    private function connecter(): TestResponse
    {
        return $this->postJson('/api/auth/login', ['username' => self::IDENTIFIANT, 'password' => 'mot-de-passe-949']);
    }
}
