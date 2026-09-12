<?php

declare(strict_types=1);

namespace Tests\Feature\Retention;

use App\Models\Institution;
use App\Models\User;
use App\Services\Retention\RetentionPolicy;
use App\Services\Retention\RetentionRegistry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #690 — les garanties de purge tiennent pour TOUS les domaines, sans exception
 * possible, et un domaine neuf s'ajoute sans toucher à la commande.
 *
 * ## Ce que ce fichier protège
 *
 * Six commandes de purge coexistaient, dont trois partageant un squelette
 * recopié à la main. Les écarts n'étaient pas des décisions :
 * `PurgeSoftDeletedInstitutions` ne découpait pas en lots,
 * `PurgeSeanceRecordings` n'écrivait aucune trace d'audit, et le message final
 * des institutions comptait comme purgées celles qu'il venait d'épargner.
 *
 * Sur de la destruction définitive, ces oublis sont la pire catégorie de dette.
 *
 * @see app/Services/Retention/RetentionRunner.php
 * @see app/Console/Commands/PurgeRetention.php
 */
final class PurgeRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurEnCorbeilleDepuis(int $jours): User
    {
        $user = User::factory()->create(['email' => 'u'.uniqid().'@690.test']);
        $user->delete();
        $user->forceFill(['deleted_at' => now()->subDays($jours)])->saveQuietly();

        return $user;
    }

    public function test_par_defaut_la_commande_simule_et_ne_detruit_rien(): void
    {
        $user = $this->utilisateurEnCorbeilleDepuis(90);

        $this->artisan('purge:run', ['domaine' => 'users'])
            ->expectsOutputToContain('[SIMULATION]')
            ->assertExitCode(0);

        // Toujours là — en corbeille, mais bien présent en base.
        $this->assertNotNull(User::withTrashed()->find($user->getKey()));
    }

    public function test_avec_force_elle_detruit_et_trace_avant(): void
    {
        $user = $this->utilisateurEnCorbeilleDepuis(90);
        $id = $user->getKey();

        $this->artisan('purge:run', ['domaine' => 'users', '--force' => true])
            ->assertExitCode(0);

        $this->assertNull(User::withTrashed()->find($id));
        // La trace est écrite AVANT la destruction : sans cet ordre, l'entrée
        // n'aurait plus de sujet.
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.purged']);
    }

    public function test_le_delai_de_grace_protege_les_suppressions_recentes(): void
    {
        $recent = $this->utilisateurEnCorbeilleDepuis(3);

        $this->artisan('purge:run', ['domaine' => 'users', '--force' => true])
            ->assertExitCode(0);

        $this->assertNotNull(User::withTrashed()->find($recent->getKey()));
    }

    public function test_un_delai_negatif_retombe_sur_le_defaut_plutot_que_de_tout_detruire(): void
    {
        // `--days=-99` placerait le seuil dans le FUTUR : tout serait éligible,
        // y compris une suppression d'il y a une minute.
        $recent = $this->utilisateurEnCorbeilleDepuis(1);

        $this->artisan('purge:run', ['domaine' => 'users', '--days' => '-99', '--force' => true])
            ->assertExitCode(0);

        $this->assertNotNull(User::withTrashed()->find($recent->getKey()));
    }

    public function test_un_refus_est_compte_a_part_et_n_est_pas_annonce_comme_purge(): void
    {
        // Une institution encore peuplée : la détruire orphelinerait ses lignes.
        $institution = Institution::factory()->create();
        User::factory()->create(['institution_id' => $institution->id]);
        $institution->delete();
        $institution->forceFill(['deleted_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('purge:run', ['domaine' => 'institutions', '--force' => true])
            ->expectsOutputToContain('épargné')
            ->assertExitCode(0);

        $this->assertNotNull(Institution::withTrashed()->find($institution->id));
        // Et surtout : aucune trace d'audit pour une destruction qui n'a pas eu
        // lieu. Tracer avant d'interroger le refus aurait menti dans le journal.
        $this->assertDatabaseMissing('audit_logs', ['action' => 'institution.purged']);
    }

    public function test_un_domaine_inconnu_echoue_au_lieu_de_ne_rien_faire(): void
    {
        $this->artisan('purge:run', ['domaine' => 'nimporte-quoi'])
            ->expectsOutputToContain('Domaine inconnu')
            ->assertExitCode(2);
    }

    public function test_l_alias_historique_garde_sa_signature(): void
    {
        $user = $this->utilisateurEnCorbeilleDepuis(90);

        $this->artisan('users:purge-deleted', ['--force' => true])->assertExitCode(0);

        $this->assertNull(User::withTrashed()->find($user->getKey()));
    }

    /**
     * LE critère de l'issue : « un test prouve qu'une politique nouvelle est
     * exécutée SANS modifier la commande ».
     *
     * La politique ci-dessous n'existe nulle part dans `app/`. Elle est déclarée
     * ici, enregistrée dans le registre, et la commande l'exécute — sans qu'une
     * seule de ses lignes ait changé. C'est l'ouverture à l'extension, prouvée
     * plutôt qu'affirmée.
     */
    public function test_une_politique_inconnue_du_code_est_executee_sans_toucher_a_la_commande(): void
    {
        $user = $this->utilisateurEnCorbeilleDepuis(90);

        $this->app->bind(RetentionRegistry::class, fn (): RetentionRegistry => new RetentionRegistry([
            new class implements RetentionPolicy
            {
                public function key(): string
                {
                    return 'domaine-de-test';
                }

                public function label(): string
                {
                    return 'chose(s) de test';
                }

                public function defaultGraceDays(): int
                {
                    return 7;
                }

                public function eligible(CarbonInterface $cutoff): Builder
                {
                    return User::onlyTrashed()->where('deleted_at', '<', $cutoff);
                }

                public function describe(Model $item): string
                {
                    return 'chose de test #'.$item->getKey();
                }

                public function auditAction(): string
                {
                    return 'test.purged';
                }

                public function refuses(Model $item): ?string
                {
                    return null;
                }

                public function purge(Model $item): void
                {
                    $item->forceDelete();
                }
            },
        ]));

        $this->artisan('purge:run', ['domaine' => 'domaine-de-test', '--force' => true])
            ->expectsOutputToContain('chose(s) de test')
            ->assertExitCode(0);

        $this->assertNull(User::withTrashed()->find($user->getKey()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'test.purged']);
    }
}
