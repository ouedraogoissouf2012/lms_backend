<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Enums\InstitutionMode;
use App\Enums\TrainingSessionStatus;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #845 — une période sort enfin du brouillon.
 *
 * ## Le défaut
 *
 * `TrainingSessionCrudService:55` écrivait `Brouillon`, et c'était le **seul**
 * écrivain de `status` dans tout `app/`. Cinq états sur six étaient donc
 * inatteignables, et l'écran livré par `frontend_lms#405` affichait
 * « Brouillon » pour toutes les périodes, à jamais.
 *
 * ## Ce que ces tests gardent SURTOUT
 *
 * Élargir une machine d'états est l'occasion classique de trop ouvrir. **Neuf
 * des dix-sept cas sont des refus** : chaque transition interdite par ADR-845-01
 * a son test, et l'irréversibilité de l'annulation en a deux. Les deux derniers
 * (#968) figent le message de succès, que l'écran affiche tel quel.
 *
 * @see docs/adr/2026-09-22-845-01-transitions-de-la-periode.md
 */
final class TrainingSessionTransitionsTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────── le chemin nominal, de bout en bout

    public function test_le_cycle_complet_brouillon_publiee_cloturee_archivee(): void
    {
        [$acteur, $periode] = $this->periode();

        $this->agir($acteur, $periode, 'publier')->assertOk()
            ->assertJsonPath('data.status', TrainingSessionStatus::Publiee->value);

        $this->agir($acteur, $periode, 'cloturer')->assertOk()
            ->assertJsonPath('data.status', TrainingSessionStatus::Cloturee->value);

        $this->agir($acteur, $periode, 'archiver')->assertOk()
            ->assertJsonPath('data.status', TrainingSessionStatus::Archivee->value);
    }

    public function test_une_periode_annulee_puis_archivee(): void
    {
        [$acteur, $periode] = $this->periode();

        $this->agir($acteur, $periode, 'annuler')->assertOk();
        $this->agir($acteur, $periode, 'archiver')->assertOk()
            ->assertJsonPath('data.status', TrainingSessionStatus::Archivee->value);
    }

    public function test_la_reponse_porte_aussi_la_phase_derivee(): void
    {
        // `phase` n'est PAS une colonne (ADR-711-04) : elle se dérive des dates.
        // L'écran affiche les deux, il doit donc les recevoir toutes deux.
        [$acteur, $periode] = $this->periode();

        $this->agir($acteur, $periode, 'publier')
            ->assertOk()
            ->assertJsonStructure(['data' => ['status', 'phase']]);
    }

    // ───────────────────────── publier exige un calendrier

    public function test_publier_sans_date_de_debut_est_refuse(): void
    {
        // `phase` se dérive des dates : sans `starts_on`, une période publiée
        // n'aurait AUCUNE phase calculable.
        [$acteur, $periode] = $this->periode(['starts_on' => null]);

        $this->agir($acteur, $periode, 'publier')->assertStatus(422);

        self::assertSame(
            TrainingSessionStatus::Brouillon,
            $this->relire($periode)->status,
            'Un refus qui écrirait quand même serait pire qu\'aucune garde.'
        );
    }

    // ───────────────────────── les transitions interdites

    public function test_un_brouillon_ne_se_cloture_pas(): void
    {
        [$acteur, $periode] = $this->periode();

        $this->agir($acteur, $periode, 'cloturer')->assertStatus(409);
    }

    public function test_un_brouillon_ne_s_archive_pas(): void
    {
        [$acteur, $periode] = $this->periode();

        $this->agir($acteur, $periode, 'archiver')->assertStatus(409);
    }

    public function test_une_periode_publiee_ne_se_republie_pas(): void
    {
        [$acteur, $periode] = $this->periode();
        $this->agir($acteur, $periode, 'publier')->assertOk();

        $this->agir($acteur, $periode, 'publier')->assertStatus(409);
    }

    public function test_une_periode_cloturee_ne_se_publie_pas(): void
    {
        [$acteur, $periode] = $this->periode();
        $this->agir($acteur, $periode, 'publier')->assertOk();
        $this->agir($acteur, $periode, 'cloturer')->assertOk();

        $this->agir($acteur, $periode, 'publier')->assertStatus(409);
    }

    public function test_une_annulation_est_IRREVERSIBLE(): void
    {
        // ADR-711-04 refuse « reportée » parce qu'elle créerait un état dont on
        // ne sort pas. Le raisonnement vaut en sens inverse : une annulation
        // défaisable ne vaudrait rien pour ceux qu'elle informe.
        [$acteur, $periode] = $this->periode();
        $this->agir($acteur, $periode, 'annuler')->assertOk();

        $this->agir($acteur, $periode, 'publier')->assertStatus(409);
        $this->agir($acteur, $periode, 'cloturer')->assertStatus(409);

        self::assertSame(TrainingSessionStatus::Annulee, $this->relire($periode)->status);
    }

    public function test_une_periode_archivee_est_terminale(): void
    {
        [$acteur, $periode] = $this->periode();
        $this->agir($acteur, $periode, 'annuler')->assertOk();
        $this->agir($acteur, $periode, 'archiver')->assertOk();

        foreach (['publier', 'annuler', 'cloturer', 'archiver'] as $operation) {
            $this->agir($acteur, $periode, $operation)->assertStatus(409);
        }

        self::assertSame(TrainingSessionStatus::Archivee, $this->relire($periode)->status);
    }

    public function test_le_refus_porte_le_statut_COURANT(): void
    {
        // « Transition invalide » n'apprend rien à qui vient de cliquer.
        [$acteur, $periode] = $this->periode();

        $this->agir($acteur, $periode, 'cloturer')
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m): bool => is_string($m) && str_contains($m, 'brouillon'));
    }

    // ───────────────────────── les gardes d'accès

    public function test_la_periode_d_une_AUTRE_ecole_est_introuvable(): void
    {
        [, $ailleurs] = $this->periode();
        [$voisin] = $this->periode();

        $this->agir($voisin, $ailleurs, 'publier')->assertStatus(404);

        self::assertSame(TrainingSessionStatus::Brouillon, $this->relire($ailleurs)->status);
    }

    public function test_un_enseignant_ne_publie_pas_une_periode(): void
    {
        // Organiser une formation est un acte de gestion : un enseignant anime,
        // il ne date pas la session.
        [, $periode, $ecole] = $this->periode();
        $enseignant = User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'role' => 'enseignant',
        ]);

        $this->agir($enseignant, $periode, 'publier')->assertStatus(403);
    }

    public function test_un_etudiant_ne_touche_a_rien(): void
    {
        [, $periode, $ecole] = $this->periode();
        $etudiant = User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'role' => 'etudiant',
        ]);

        $this->agir($etudiant, $periode, 'annuler')->assertStatus(403);
    }

    public function test_purgee_n_est_atteignable_par_aucune_route(): void
    {
        // Ce n'est pas une décision humaine mais l'issue d'une politique de
        // rétention, qui reste à écrire. Aucune opération ne doit y mener.
        [$acteur, $periode] = $this->periode();

        foreach (['publier', 'annuler', 'cloturer', 'archiver'] as $operation) {
            $this->agir($acteur, $periode, $operation);
        }

        self::assertNotSame(TrainingSessionStatus::Purgee, $this->relire($periode)->status);
    }

    // ───────────────────────── le message que l'écran affiche tel quel

    /** @return iterable<string, array{0: InstitutionMode, 1: array<string, mixed>}> */
    public static function volets(): iterable
    {
        // L'acteur klassci est le compte par défaut de la fabrique (issu de KLASSCI) ;
        // l'acteur standalone est un compte local, sans aucune identité KLASSCI.
        yield 'ecole klassci (compte issu de KLASSCI)' => [InstitutionMode::Klassci, []];
        yield 'ecole standalone (compte local)' => [InstitutionMode::Standalone, [
            'klassci_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_token' => null,
            'klassci_data' => null,
        ]];
    }

    /**
     * #968 — l'écran de pilotage (frontend_lms#391) affiche `message` sans le
     * retoucher : il doit être écrit en français correct, accents compris.
     * Le message ne dépend pas du mode ; les deux volets le prouvent.
     *
     * @param  array<string, mixed>  $compte
     */
    #[DataProvider('volets')]
    public function test_publier_cloturer_archiver_disent_leur_message_accentue(InstitutionMode $mode, array $compte): void
    {
        [$acteur, $periode] = $this->periode([], $mode, $compte);

        $this->agir($acteur, $periode, 'publier')->assertOk()
            ->assertJsonPath('message', 'Période publiée.');
        $this->agir($acteur, $periode, 'cloturer')->assertOk()
            ->assertJsonPath('message', 'Période clôturée.');
        $this->agir($acteur, $periode, 'archiver')->assertOk()
            ->assertJsonPath('message', 'Période archivée.');
    }

    /**
     * Méthode à part : une seconde période exigerait un second acteur, et le garde
     * mémoïse le premier d'une requête à l'autre.
     *
     * @param  array<string, mixed>  $compte
     */
    #[DataProvider('volets')]
    public function test_annuler_dit_son_message_accentue(InstitutionMode $mode, array $compte): void
    {
        [$acteur, $periode] = $this->periode([], $mode, $compte);

        $this->agir($acteur, $periode, 'annuler')->assertOk()
            ->assertJsonPath('message', 'Période annulée. Les inscriptions déjà faites sont conservées.');
    }

    // ───────────────────────── harnais

    /**
     * @param  array<string, mixed>  $attributs
     * @param  array<string, mixed>  $compte  surcharge de l'acteur (origine du compte)
     * @return array{0: User, 1: TrainingSession, 2: Institution}
     */
    private function periode(
        array $attributs = [],
        InstitutionMode $mode = InstitutionMode::Standalone,
        array $compte = [],
    ): array {
        $ecole = Institution::factory()->create(['mode' => $mode]);
        app(TenantManager::class)->set($ecole);

        $acteur = User::factory()->create(array_merge([
            'institution_id' => $ecole->getKey(),
            'role' => 'coordinateur',
        ], $compte));

        $programme = Program::factory()->create(['institution_id' => $ecole->getKey()]);

        $periode = TrainingSession::factory()->create(array_merge([
            'institution_id' => $ecole->getKey(),
            'program_id' => $programme->getKey(),
            'status' => TrainingSessionStatus::Brouillon,
            'starts_on' => now()->addMonth()->toDateString(),
        ], $attributs));

        return [$acteur, $periode, $ecole];
    }

    private function relire(TrainingSession $periode): TrainingSession
    {
        return TrainingSession::query()->withoutGlobalScopes()->findOrFail($periode->getKey());
    }

    private function agir(User $acteur, TrainingSession $periode, string $operation): \Illuminate\Testing\TestResponse
    {
        // Un VRAI jeton : `Sanctum::actingAs` n'en émet aucun, `ResolveInstitution`
        // ne pose alors aucun tenant, et le scope multi-tenant s'efface.
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$acteur->createToken('api-845')->plainTextToken,
        ])->postJson('/api/training-sessions/'.$periode->getKey().'/'.$operation);
    }
}
