<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #818 — déclarer le mode d'un établissement depuis l'écran d'administration.
 *
 * ## Le manque que ce fichier ferme
 *
 * `institutions.mode` existe depuis #814, mais `InstitutionController` ne
 * l'acceptait ni en création ni en mise à jour : zéro occurrence du mot. Le
 * SEUL écrivain de la colonne dans toute l'application était
 * `SchoolRequestDecisionService:97`, au bout du parcours de demande publique.
 *
 * Conséquence mesurée : toute institution créée par le supradmin depuis l'écran
 * naissait `klassci` et le restait à jamais — et recevait donc un 403 sur
 * l'assistant d'import, dont la porte est `allowsLocalEnrolment()`. La
 * fonctionnalité livrée en #718 était hors d'atteinte pour ces écoles.
 *
 * ## Pourquoi la bascule a sa propre route
 *
 * Changer le mode d'un établissement déjà peuplé ne doit pas être l'effet de
 * bord d'un `PUT` de branding. Le dépôt sépare déjà les changements d'état
 * délibérés du CRUD générique — `PATCH /{id}/toggle` pour `is_active` — et
 * cette route suit ce précédent, trace d'audit comprise.
 *
 * @see docs/adr/2026-09-17-818-01-declaration-du-mode.md
 */
final class InstitutionModeDeclarationTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private User $supradmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('818')->plainTextToken];
    }

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function ecoleValide(array $surcharges = []): array
    {
        return array_merge([
            'slug' => 'cabinet-kouassi',
            'name' => 'Cabinet Kouassi Formation',
        ], $surcharges);
    }

    public function test_sans_declaration_une_institution_nait_klassci(): void
    {
        // Non-régression : `klassci` est le défaut et le reste. Aucune ligne
        // existante ne change de comportement (#814).
        $this->postJson('/api/admin/institutions', $this->ecoleValide(), $this->bearer($this->supradmin))
            ->assertStatus(201);

        self::assertSame(
            InstitutionMode::Klassci,
            Institution::query()->where('slug', 'cabinet-kouassi')->firstOrFail()->mode,
        );
    }

    public function test_le_supradmin_ouvre_une_ecole_autonome_des_la_creation(): void
    {
        $this->postJson(
            '/api/admin/institutions',
            $this->ecoleValide(['mode' => 'standalone']),
            $this->bearer($this->supradmin),
        )->assertStatus(201);

        self::assertSame(
            InstitutionMode::Standalone,
            Institution::query()->where('slug', 'cabinet-kouassi')->firstOrFail()->mode,
        );
    }

    public function test_un_mode_hors_enum_est_refuse(): void
    {
        // La colonne est un ENUM applicatif : une valeur libre y entrerait par
        // le `$fillable` et ferait échouer la lecture au point de liaison.
        $this->postJson(
            '/api/admin/institutions',
            $this->ecoleValide(['mode' => 'autonome']),
            $this->bearer($this->supradmin),
        )->assertStatus(422)->assertJsonPath('errors.mode.0', fn ($m) => is_string($m));

        self::assertNull(Institution::query()->where('slug', 'cabinet-kouassi')->first());
    }

    public function test_le_put_de_branding_ne_peut_pas_basculer_le_mode(): void
    {
        // Le cœur de la décision : changer l'autorité d'inscription de toute une
        // école ne doit jamais être l'effet de bord d'un changement de couleur.
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);

        $this->putJson(
            '/api/admin/institutions/'.$ecole->id,
            ['primary_color' => '#ff0000', 'mode' => 'standalone'],
            $this->bearer($this->supradmin),
        )->assertOk();

        $frais = $ecole->fresh();

        self::assertNotNull($frais);
        self::assertSame(InstitutionMode::Klassci, $frais->mode, 'Un PUT de branding a basculé le mode.');
        self::assertSame('#ff0000', $frais->primary_color, 'Le reste du PUT doit continuer de passer.');
    }

    public function test_la_bascule_dediee_change_le_mode_et_laisse_une_trace(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);

        $this->patchJson(
            '/api/admin/institutions/'.$ecole->id.'/mode',
            ['mode' => 'standalone'],
            $this->bearer($this->supradmin),
        )->assertOk();

        self::assertSame(InstitutionMode::Standalone, $ecole->fresh()?->mode);

        // Une bascule sans trace serait indéfendable : c'est le geste qui ouvre
        // ou ferme l'inscription locale pour un établissement entier.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'institution.mode_changed',
            'auditable_id' => $ecole->id,
        ]);
    }

    public function test_la_bascule_accepte_le_retour_en_arriere(): void
    {
        // Rien ne se perd en basculant : `CompositeEnrollmentSource` ne lit pas
        // le mode, et un import déjà analysé est revérifié à la confirmation.
        // La bascule est donc réversible, et le test le dit explicitement.
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);

        $this->patchJson(
            '/api/admin/institutions/'.$ecole->id.'/mode',
            ['mode' => 'klassci'],
            $this->bearer($this->supradmin),
        )->assertOk();

        self::assertSame(InstitutionMode::Klassci, $ecole->fresh()?->mode);
    }

    public function test_la_bascule_survit_a_un_audit_indisponible(): void
    {
        // #241 a tranché : un échec d'écriture de l'audit ne casse JAMAIS
        // l'action métier — `AuditLogger::write()` avale ses propres échecs.
        // Ce test tient cette décision pour la route neuve, et explique du même
        // coup pourquoi aucune transaction n'entoure la bascule et sa trace :
        // il n'y aurait rien à annuler.
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        Schema::drop('audit_logs');

        $this->patchJson(
            '/api/admin/institutions/'.$ecole->id.'/mode',
            ['mode' => 'standalone'],
            $this->bearer($this->supradmin),
        )->assertOk();

        self::assertSame(InstitutionMode::Standalone, $ecole->fresh()?->mode);
    }

    public function test_une_bascule_sans_mode_est_refusee(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);

        $this->patchJson(
            '/api/admin/institutions/'.$ecole->id.'/mode',
            [],
            $this->bearer($this->supradmin),
        )->assertStatus(422);

        self::assertSame(InstitutionMode::Klassci, $ecole->fresh()?->mode);
    }

    public function test_la_bascule_est_fermee_a_qui_n_est_pas_supradmin_plateforme(): void
    {
        // Même double garde que le reste du CRUD cross-tenant (#511).
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $admin = User::factory()->admin()->create(['institution_id' => $ecole->id]);

        $this->patchJson(
            '/api/admin/institutions/'.$ecole->id.'/mode',
            ['mode' => 'standalone'],
            $this->bearer($admin),
        )->assertStatus(403);

        self::assertSame(InstitutionMode::Klassci, $ecole->fresh()?->mode);
    }

    public function test_la_liste_expose_le_mode_pour_que_l_ecran_puisse_l_afficher(): void
    {
        // Accepter le mode en écriture sans le rendre en lecture laisserait
        // l'écran aveugle : il ne saurait ni l'afficher ni proposer la bascule.
        Institution::factory()->create(['slug' => 'ecole-autonome', 'mode' => InstitutionMode::Standalone]);

        $liste = $this->getJson('/api/admin/institutions', $this->bearer($this->supradmin))
            ->assertOk()
            ->json('data.institutions');

        $trouvee = collect($liste)->firstWhere('slug', 'ecole-autonome');

        self::assertIsArray($trouvee);
        self::assertSame('standalone', $trouvee['mode']);
    }

    public function test_declarer_le_mode_ouvre_reellement_l_import_local(): void
    {
        // La preuve que ce lot débloque #718 : avant, une école créée par cet
        // écran recevait un 403 sur l'assistant d'import, quoi que fasse le
        // supradmin. Le parcours complet est mesuré, pas déduit.
        $this->disableKlassciMiddleware();

        $this->postJson(
            '/api/admin/institutions',
            $this->ecoleValide(['mode' => 'standalone']),
            $this->bearer($this->supradmin),
        )->assertStatus(201);

        $ecole = Institution::query()->where('slug', 'cabinet-kouassi')->firstOrFail();
        app(TenantManager::class)->set($ecole);
        $enseignant = User::factory()->teacher()->create(['institution_id' => $ecole->id]);

        $this->asTenant($enseignant)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', "nom;prenom;email\nDoe;Jane;jane@test.ci\n"),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.counts.ok', 1);
    }

    public function test_une_ecole_restee_klassci_refuse_toujours_l_import(): void
    {
        // Le pendant du test précédent : sans déclaration, la porte reste fermée.
        // Sans lui, le test d'ouverture pourrait passer pour une mauvaise raison.
        $this->disableKlassciMiddleware();

        $this->postJson('/api/admin/institutions', $this->ecoleValide(), $this->bearer($this->supradmin))
            ->assertStatus(201);

        $ecole = Institution::query()->where('slug', 'cabinet-kouassi')->firstOrFail();
        app(TenantManager::class)->set($ecole);
        $enseignant = User::factory()->teacher()->create(['institution_id' => $ecole->id]);

        $this->asTenant($enseignant)
            ->post('/api/lms/imports/preview', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', "nom;prenom;email\nDoe;Jane;jane@test.ci\n"),
            ], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }
}
