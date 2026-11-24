<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Services\Tenancy\InstitutionIntegrityInspectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #719 (B7) — `config/tenancy.php` et le schéma réel disent la même chose, dans les DEUX sens.
 *
 * La règle était écrite en commentaire (« toute nouvelle table tenant-scopée DOIT être ajoutée
 * ici ET recevoir sa clé étrangère »), et `InstitutionForeignKeyTest` n'en vérifiait qu'un sens :
 * une table de la liste a sa clé. Mesuré le 23/11 sur un schéma neuf : 39 tables portent
 * `institution_id`, la liste en déclare 34, et les cinq autres n'étaient vues par rien.
 *
 * Une table qui porte `institution_id` est donc, soit DÉCLARÉE (et porte sa clé), soit une
 * EXCEPTION nommée avec sa raison. Une exception doit exister dans le schéma : une exception
 * morte masquerait une table renommée.
 *
 * Le schéma est lu par {@see InstitutionIntegrityInspectorInterface}, pas par un second
 * inspecteur. Le dénominateur est publié : « rien à redire » et « rien regardé » ne
 * produisent pas le même message.
 */
final class LaConfigDesTenantsSuitLeSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** En dessous, la garde ne regarde pas où elle croit (39 tables mesurées le 23/11). */
    private const PLANCHER = 30;

    public function test_le_schema_et_la_configuration_disent_la_meme_chose(): void
    {
        $inspecteur = $this->app->make(InstitutionIntegrityInspectorInterface::class);
        $toutes = array_column(Schema::getTables(Schema::getCurrentSchemaListing()), 'name');
        $portantLaColonne = $inspecteur->scopedTablesPresent($toutes);

        $ecarts = $this->ecarts(
            $toutes,
            $portantLaColonne,
            $this->declarees(),
            $this->exceptions(),
            static fn (string $table): bool => $inspecteur->hasInstitutionForeignKey($table),
        );

        self::assertGreaterThanOrEqual(self::PLANCHER, count($portantLaColonne), sprintf(
            'Seulement %d tables portant institution_id sur %d : la garde ne regarde pas où elle croit.',
            count($portantLaColonne),
            count($toutes),
        ));
        self::assertSame([], $ecarts, sprintf(
            '%d tables lues, %d portent institution_id, %d déclarées, %d exceptions.',
            count($toutes),
            count($portantLaColonne),
            count($this->declarees()),
            count($this->exceptions()),
        ));
    }

    public function test_une_table_portant_la_colonne_ni_declaree_ni_exceptee_fait_rougir(): void
    {
        $ecarts = $this->ecarts(['users', 'nouvelle'], ['users', 'nouvelle'], ['users'], [], fn (): bool => true);

        self::assertSame(['non déclarée : nouvelle'], $ecarts);
    }

    public function test_une_table_declaree_sans_cle_fait_rougir(): void
    {
        $ecarts = $this->ecarts(['users'], ['users'], ['users'], [], fn (): bool => false);

        self::assertSame(['déclarée sans clé étrangère : users'], $ecarts);
    }

    public function test_une_table_declaree_absente_du_schema_fait_rougir(): void
    {
        $ecarts = $this->ecarts(['users'], ['users'], ['users', 'renommee'], [], fn (): bool => true);

        self::assertSame(['déclarée mais absente du schéma : renommee'], $ecarts);
    }

    public function test_une_exception_morte_fait_rougir(): void
    {
        $ecarts = $this->ecarts(['users'], ['users'], ['users'], ['disparue' => 'une raison'], fn (): bool => true);

        self::assertSame(['exception absente du schéma : disparue'], $ecarts);
    }

    public function test_une_exception_sans_colonne_fait_rougir(): void
    {
        $ecarts = $this->ecarts(['users', 'sans'], ['users'], ['users'], ['sans' => 'une raison'], fn (): bool => true);

        self::assertSame(['exception sans colonne institution_id : sans'], $ecarts);
    }

    public function test_une_exception_sans_raison_fait_rougir(): void
    {
        $ecarts = $this->ecarts(['users', 'journal'], ['users', 'journal'], ['users'], ['journal' => ' '], fn (): bool => true);

        self::assertSame(['exception sans raison : journal'], $ecarts);
    }

    public function test_une_table_declaree_et_exceptee_fait_rougir(): void
    {
        $ecarts = $this->ecarts(['users'], ['users'], ['users'], ['users' => 'une raison'], fn (): bool => true);

        self::assertSame(['déclarée ET exceptée : users'], $ecarts);
    }

    /**
     * @param  list<string>  $toutes
     * @param  list<string>  $portantLaColonne
     * @param  list<string>  $declarees
     * @param  array<array-key, mixed>  $exceptions
     * @param  callable(string): bool  $aSaCle
     * @return list<string>
     */
    private function ecarts(array $toutes, array $portantLaColonne, array $declarees, array $exceptions, callable $aSaCle): array
    {
        $ecarts = [];

        foreach ($portantLaColonne as $table) {
            if (! in_array($table, $declarees, true) && ! array_key_exists($table, $exceptions)) {
                $ecarts[] = 'non déclarée : '.$table;
            }
        }

        foreach ($declarees as $table) {
            $ecarts[] = match (true) {
                ! in_array($table, $toutes, true) => 'déclarée mais absente du schéma : '.$table,
                ! in_array($table, $portantLaColonne, true) => 'déclarée sans colonne institution_id : '.$table,
                ! $aSaCle($table) => 'déclarée sans clé étrangère : '.$table,
                default => null,
            };
        }

        foreach ($exceptions as $table => $raison) {
            $table = (string) $table;
            $ecarts[] = match (true) {
                ! in_array($table, $toutes, true) => 'exception absente du schéma : '.$table,
                ! in_array($table, $portantLaColonne, true) => 'exception sans colonne institution_id : '.$table,
                ! is_string($raison) || trim($raison) === '' => 'exception sans raison : '.$table,
                in_array($table, $declarees, true) => 'déclarée ET exceptée : '.$table,
                default => null,
            };
        }

        return array_values(array_filter($ecarts, static fn (?string $ecart): bool => $ecart !== null));
    }

    /** @return list<string> */
    private function declarees(): array
    {
        $tables = config('tenancy.institution_scoped_tables', []);

        return is_array($tables) ? array_values(array_filter($tables, 'is_string')) : [];
    }

    /** @return array<array-key, mixed> */
    private function exceptions(): array
    {
        $exceptions = config('tenancy.institution_scoped_exceptions', []);

        return is_array($exceptions) ? $exceptions : [];
    }
}
