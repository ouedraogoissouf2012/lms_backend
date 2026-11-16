<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
use App\Exceptions\BusinessException;
use App\Models\Institution;
use App\Services\Catalogue\ClassesLocalesQuery;
use App\Services\Catalogue\LocalClasseCreator;
use App\Services\Catalogue\LocalClasseMatiereLinker;
use App\Services\Catalogue\LocalMatiereCreator;
use App\Services\Enrollment\ClasseEnrolmentCodeService;
use App\Services\TenantManager;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #910 — une seule garde « catalogue et établissement », pour les cinq services.
 *
 * Les services sont appelés DIRECTEMENT : par HTTP, la porte d'appartenance
 * (`EnsureSchoolMemberOrPlatformRoute`) refuse avant eux tout compte sans
 * établissement. Leur garde protège les autres appelants (console, job, second
 * contrôleur), et c'est là que l'ordre et le motif se vérifient.
 *
 * Ce qui est exigé, pour chacun des cinq :
 * - sans établissement : 409, motif `no_institution`, AVANT le droit du
 *   catalogue (l'ordre de #905) ;
 * - école KLASSCI : 403, et le MÊME message pour les cinq ;
 * - école autonome : la garde laisse passer ;
 * - la garde relit l'école à CHAQUE appel : un même objet servant deux écoles
 *   successives ne rejoue pas le droit de la première (une école autonome puis
 *   une école KLASSCI : la seconde écrirait un catalogue que KLASSCI tient).
 *
 * Pour « laisser passer », les identifiants inexistants suffisent : un 404
 * « introuvable » prouve que la garde est franchie, sans fixture de plus.
 */
final class PorteDuCatalogueLocalTest extends TestCase
{
    use RefreshDatabase;

    private const REFUS = 'Le catalogue de cet établissement vient de KLASSCI.';

    private const ABSENT = 999999;

    /**
     * @return array<string, array{0: class-string, 1: Closure(object): mixed}>
     */
    public static function services(): array
    {
        return [
            'classe créée' => [LocalClasseCreator::class, static fn (LocalClasseCreator $s): mixed => $s->creer(['libelle' => 'Porte'])],
            'matière créée' => [LocalMatiereCreator::class, static fn (LocalMatiereCreator $s): mixed => $s->creer(['libelle' => 'Porte'])],
            'programme composé' => [LocalClasseMatiereLinker::class, static fn (LocalClasseMatiereLinker $s): mixed => $s->rattacher(self::ABSENT, self::ABSENT)],
            'code émis' => [ClasseEnrolmentCodeService::class, static fn (ClasseEnrolmentCodeService $s): mixed => $s->generer(self::ABSENT)],
            'code retiré' => [ClasseEnrolmentCodeService::class, static fn (ClasseEnrolmentCodeService $s): mixed => $s->revoquer(self::ABSENT)],
            'classes locales lues' => [ClassesLocalesQuery::class, static fn (ClassesLocalesQuery $s): mixed => $s->lister(15)],
        ];
    }

    /**
     * @param  class-string  $service
     * @param  Closure(object): mixed  $appel
     */
    #[DataProvider('services')]
    public function test_sans_etablissement_le_409_motive_passe_avant_le_droit(string $service, Closure $appel): void
    {
        app(TenantManager::class)->reset();

        $refus = $this->refus(fn (): mixed => $appel(app($service)));

        self::assertSame(409, $refus->statutHttp(), $refus->getMessage());
        self::assertSame('no_institution', $refus->reason);
        self::assertSame('Aucun établissement résolu.', $refus->getMessage());
    }

    /**
     * @param  class-string  $service
     * @param  Closure(object): mixed  $appel
     */
    #[DataProvider('services')]
    public function test_une_ecole_klassci_recoit_le_meme_refus_partout(string $service, Closure $appel): void
    {
        $this->pourEcole(InstitutionMode::Klassci);

        $refus = $this->refus(fn (): mixed => $appel(app($service)));

        self::assertSame(403, $refus->statutHttp());
        self::assertSame(self::REFUS, $refus->getMessage());
    }

    /**
     * @param  class-string  $service
     * @param  Closure(object): mixed  $appel
     */
    #[DataProvider('services')]
    public function test_une_ecole_autonome_franchit_la_garde(string $service, Closure $appel): void
    {
        $this->pourEcole(InstitutionMode::Standalone);

        $this->franchit(fn (): mixed => $appel(app($service)));
    }

    /**
     * @param  class-string  $service
     * @param  Closure(object): mixed  $appel
     */
    #[DataProvider('services')]
    public function test_la_garde_relit_l_ecole_a_chaque_appel(string $service, Closure $appel): void
    {
        $this->pourEcole(InstitutionMode::Standalone);
        $instance = app($service);
        $this->franchit(fn (): mixed => $appel($instance));

        // Le MÊME objet, une autre école : le droit de la première ne se rejoue pas.
        $this->pourEcole(InstitutionMode::Klassci);
        $refus = $this->refus(fn (): mixed => $appel($instance));

        self::assertSame(403, $refus->statutHttp(), 'Le droit de l\'école autonome a été rejoué pour l\'école KLASSCI.');
    }

    /**
     * @param  class-string  $service
     * @param  Closure(object): mixed  $appel
     */
    #[DataProvider('services')]
    public function test_la_garde_relit_l_ecole_dans_l_autre_sens(string $service, Closure $appel): void
    {
        $this->pourEcole(InstitutionMode::Klassci);
        $instance = app($service);
        self::assertSame(403, $this->refus(fn (): mixed => $appel($instance))->statutHttp());

        $this->pourEcole(InstitutionMode::Standalone);
        $this->franchit(fn (): mixed => $appel($instance));
    }

    private function pourEcole(InstitutionMode $mode): void
    {
        $factory = Institution::factory();
        $ecole = $mode === InstitutionMode::Standalone
            ? $factory->standalone()->create()
            : $factory->create(['mode' => InstitutionMode::Klassci]);

        app(TenantManager::class)->set($ecole);
    }

    private function refus(Closure $appel): BusinessException
    {
        try {
            $appel();
        } catch (BusinessException $refus) {
            return $refus;
        }

        self::fail('Aucun refus : la garde a laissé passer.');
    }

    /** Franchir la garde : aucun refus, ou un refus qui n'est pas le sien (404 introuvable). */
    private function franchit(Closure $appel): void
    {
        try {
            $appel();
        } catch (BusinessException $refus) {
            self::assertNotContains(
                $refus->statutHttp(),
                [403, 409],
                'La garde a refusé une école autonome : '.$refus->getMessage()
            );

            return;
        }

        $this->addToAssertionCount(1);
    }
}
