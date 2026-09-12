<?php

declare(strict_types=1);

namespace Tests\Feature\Retention;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #690 — `audit:purge` devient un alias sans changer de comportement, et les
 * deux commandes qui NE le deviennent pas gardent leur garde.
 *
 * ## Pourquoi `recordings:purge` et `chapters:purge` restent entières
 *
 * Les convertir en alias minces a été essayé, puis abandonné sur preuve : leurs
 * tests existants échouaient. Elles émettent une ligne JSON portant des
 * compteurs PROPRES À LEUR DOMAINE — `chapters_purged`,
 * `provider_files_ignored`, `trashed_total`, `oldest_trashed_at` — que les trois
 * nombres génériques du moteur ne peuvent pas exprimer.
 *
 * Cette sortie est consommée : par ces tests, et par le journal d'exploitation.
 * La faire disparaître en silence serait exactement le défaut que ce dépôt
 * traque. Leur politique les rend accessibles via `purge:run`, ce qui est
 * l'ouverture demandée ; leur commande garde sa comptabilité, ce qui est la
 * non-régression demandée. Les deux critères tiennent ensemble.
 *
 * ## Le piège que ce fichier ferme
 *
 * `audit:purge` n'a **aucun drapeau destructeur** : invoquée, elle purge. Et
 * elle est **planifiée quotidiennement** (`routes/console.php:98`).
 *
 * En faire un alias naïf de `purge:run audit-logs` lui aurait fait hériter du
 * défaut du moteur — la simulation. Le planificateur aurait continué à
 * l'exécuter chaque nuit, à rapporter un succès, et **plus rien n'aurait été
 * purgé**. Le journal aurait grossi sans limite, sans qu'aucune alerte ne se
 * déclenche.
 *
 * Un test qui ne vérifierait que le code de sortie serait vert dans les deux
 * cas. On vérifie donc ce qui reste EN BASE.
 *
 * @see app/Console/Commands/PurgeAuditLogs.php
 * @see app/Services/Retention/Policies/AuditLogsPolicy.php
 */
final class PurgeAliasesTest extends TestCase
{
    use RefreshDatabase;

    private function entreeAncienne(int $jours): int
    {
        return (int) DB::table('audit_logs')->insertGetId([
            // `audit_logs` est append-only : pas de colonne `updated_at`.
            'action' => 'test.ancien',
            'created_at' => now()->subDays($jours),
        ]);
    }

    public function test_audit_purge_sans_drapeau_detruit_toujours(): void
    {
        // LE cas du planificateur : aucun drapeau.
        $id = $this->entreeAncienne(400);

        $this->artisan('audit:purge')->assertExitCode(0);

        $this->assertNull(AuditLog::withoutGlobalScope('institution')->find($id));
    }

    public function test_audit_purge_en_simulation_ne_detruit_rien(): void
    {
        $id = $this->entreeAncienne(400);

        $this->artisan('audit:purge', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNotNull(AuditLog::withoutGlobalScope('institution')->find($id));
    }

    public function test_une_entree_recente_survit_a_la_purge(): void
    {
        $recente = $this->entreeAncienne(3);

        $this->artisan('audit:purge')->assertExitCode(0);

        $this->assertNotNull(AuditLog::withoutGlobalScope('institution')->find($recente));
    }

    /**
     * La synthèse remplace la trace par élément — sans elle, purger le journal
     * d'audit ne laisserait AUCUNE trace, et la disparition de lignes serait
     * indiscernable d'une altération.
     */
    public function test_la_purge_du_journal_laisse_une_entree_de_synthese(): void
    {
        $this->entreeAncienne(400);

        $this->artisan('audit:purge')->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', ['action' => 'audit-logs.purged.bulk']);
        // Et une seule, pas une par ligne effacée.
        $this->assertSame(1, AuditLog::withoutGlobalScope('institution')
            ->where('action', 'audit-logs.purged.bulk')->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function aliasAExclusionMutuelle(): array
    {
        return [
            'recordings' => ['recordings:purge'],
            'chapters' => ['chapters:purge'],
        ];
    }

    #[DataProvider('aliasAExclusionMutuelle')]
    public function test_ni_drapeau_ni_les_deux_ne_passent(string $commande): void
    {
        // Aucun défaut implicite sur une commande qui détruit : ni l'oubli, ni
        // la contradiction ne doivent produire une exécution.
        $this->artisan($commande)->assertExitCode(2);
        $this->artisan($commande, ['--dry-run' => true, '--apply' => true])->assertExitCode(2);
    }

    #[DataProvider('aliasAExclusionMutuelle')]
    public function test_la_simulation_explicite_est_acceptee(string $commande): void
    {
        $this->artisan($commande, ['--dry-run' => true])->assertExitCode(0);
    }
}
