<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\InstitutionMode;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Enrollment\StudentEnrolmentService;
use App\Services\Sync\Classes\ClasseStudentsSynchronizer;
use App\Services\Sync\Classes\ListeKlassciDesEleves;
use App\Services\Sync\Classes\ReconciliationKlassci;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #921, ADR-921-01 — le statut d'une adhésion a un seul écrivain par classe.
 *
 * - Classe KLASSCI : KLASSCI fait autorité. La synchronisation remet à `actif`
 *   l'élève qu'elle liste, la réconciliation passe `inactif` l'absent d'une liste
 *   complète. Aucune écriture locale.
 * - Classe locale : les portes locales. Un code ne rouvre jamais une adhésion
 *   close (#885).
 *
 * Les deux règles ne se contredisent pas parce qu'elles ne touchent jamais la
 * même classe : c'est cette frontière que ces tests figent. Ils sont verts dès
 * leur écriture, puisque la règle est déjà vraie. Leur rouge vient des
 * falsifications.
 *
 * Volets : une école KLASSCI, et une école revenue en KLASSCI après une période
 * autonome, la seule qui porte à la fois des classes locales et des classes
 * KLASSCI. L'autorité suit le mode courant.
 */
final class AutoriteDuStatutDAdhesionTest extends TestCase
{
    use RefreshDatabase;

    private const KLASSCI_ELEVE = 8801;

    private Institution $ecole;

    private User $eleve;

    private Classe $classeKlassci;

    private Classe $classeLocale;

    /**
     * @return array<string, array{bool}>
     */
    public static function volets(): array
    {
        return [
            'école KLASSCI' => [false],
            'école revenue en KLASSCI après une période autonome' => [true],
        ];
    }

    #[DataProvider('volets')]
    public function test_la_synchronisation_ne_touche_aucune_adhesion_de_classe_locale(bool $bascule): void
    {
        $this->ecole($bascule);
        $this->adhesion($this->classeLocale, 'abandonne');

        $this->synchroniser([$this->eleveKlassci()]);

        self::assertSame('abandonne', $this->statut($this->classeLocale));
        self::assertSame('actif', $this->statut($this->classeKlassci), 'La classe KLASSCI, elle, est bien synchronisée.');
    }

    #[DataProvider('volets')]
    public function test_la_reconciliation_ne_touche_aucune_adhesion_de_classe_locale(bool $bascule): void
    {
        $this->ecole($bascule);
        $this->adhesion($this->classeKlassci, 'actif');
        $this->adhesion($this->classeLocale, 'actif');

        // Liste KLASSCI complète et vide : l'élève n'y est plus.
        app(ReconciliationKlassci::class)->reconcilier($this->classeKlassci, new ListeKlassciDesEleves([], 0));

        self::assertSame('inactif', $this->statut($this->classeKlassci), 'La réconciliation a bien eu lieu.');
        self::assertSame('actif', $this->statut($this->classeLocale));
    }

    #[DataProvider('volets')]
    public function test_un_code_ne_rouvre_pas_une_adhesion_locale_close(bool $bascule): void
    {
        $this->ecole($bascule);
        $this->adhesion($this->classeLocale, 'abandonne');

        try {
            app(StudentEnrolmentService::class)->rejoindre($this->eleve, $this->classeLocale);
            self::fail('Le code a rouvert une adhésion close.');
        } catch (BusinessException $refus) {
            self::assertSame(409, $refus->statutHttp());
        }

        self::assertSame('abandonne', $this->statut($this->classeLocale));
    }

    #[DataProvider('volets')]
    public function test_klassci_rouvre_une_adhesion_close_de_sa_classe(bool $bascule): void
    {
        $this->ecole($bascule);
        $this->adhesion($this->classeKlassci, 'abandonne');

        $this->synchroniser([$this->eleveKlassci()]);

        self::assertSame('actif', $this->statut($this->classeKlassci));
    }

    private function ecole(bool $bascule): void
    {
        $this->ecole = $bascule
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create(['mode' => InstitutionMode::Klassci]);
        $this->classeLocale = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => null]);

        if ($bascule) {
            // La classe locale est née pendant la période autonome ; l'école est
            // ensuite revenue en KLASSCI.
            $this->ecole->update(['mode' => InstitutionMode::Klassci]);
        }

        app(TenantManager::class)->set($this->ecole);
        $this->classeKlassci = Classe::factory()->create(['institution_id' => $this->ecole->id, 'klassci_id' => 501]);
        $this->eleve = User::factory()->create([
            'institution_id' => $this->ecole->id,
            'role' => 'etudiant',
            'klassci_id' => self::KLASSCI_ELEVE,
        ]);
    }

    private function adhesion(Classe $classe, string $statut): void
    {
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->id,
            'user_id' => $this->eleve->id,
            'institution_id' => $this->ecole->id,
            'statut' => $statut,
            'date_inscription' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $eleves
     */
    private function synchroniser(array $eleves): void
    {
        app(ClasseStudentsSynchronizer::class)->sync($this->classeKlassci, $eleves, 'jeton');
    }

    /**
     * @return array<string, mixed>
     */
    private function eleveKlassci(): array
    {
        return ['id' => self::KLASSCI_ELEVE, 'nom' => 'Traoré', 'prenom' => 'Awa'];
    }

    private function statut(Classe $classe): ?string
    {
        $statut = DB::table('classe_etudiant')
            ->where('classe_id', $classe->id)
            ->where('user_id', $this->eleve->id)
            ->value('statut');

        return is_string($statut) ? $statut : null;
    }
}
