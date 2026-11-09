<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Catalogue\CatalogueAuthority;
use App\Services\Classe\ClasseDetailsQueryService;
use App\Services\Classe\ClasseDetailsReader;
use App\Services\Classe\ClasseEtudiantsQueryService;
use App\Services\Classe\ClasseEtudiantsReader;
use App\Services\Classe\ClasseLocalDetailsService;
use App\Services\Classe\ClasseLocalDoorReader;
use App\Services\Classe\InstitutionClassesReader;
use App\Services\Classe\KlassciInstitutionClassesReader;
use App\Services\Classe\LocalClasseDetailsReader;
use App\Services\Classe\LocalInstitutionClassesReader;
use App\Services\Classe\LocalTeacherClassesReader;
use App\Services\Classe\TeacherClassesQueryService;
use App\Services\Classe\TeacherClassesReader;
use App\Services\Matiere\LocalMatiereDetailsReader;
use App\Services\Matiere\LocalTeacherMatieresReader;
use App\Services\Matiere\MatiereDetailsQueryService;
use App\Services\Matiere\MatiereDetailsReader;
use App\Services\Matiere\MyMatieresQueryService;
use App\Services\Matiere\TeacherMatieresReader;
use App\Services\Roster\RosterAuthorityFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Liaison de l'autorité sur le catalogue pédagogique (#848), et des sources
 * qui le LISENT (école créée depuis le LMS : lot 1, les matières ; lot 2, les
 * classes) : la même route sert les deux types d'école, la fabrique choisit la
 * source.
 *
 * Fournisseur dédié, comme `RosterServiceProvider` et `RetentionServiceProvider`
 * avant lui. Ce n'est pas un choix de style : `AppServiceProvider` mesure 299
 * lignes pour une garde à 300, et son propre docblock rappelle que la CI a déjà
 * refusé une liaison de cinq lignes ajoutée là.
 *
 * @see docs/adr/2026-09-18-848-01-catalogue-pedagogique-local.md
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
final class CatalogueServiceProvider extends ServiceProvider
{
    /**
     * `bind()` et JAMAIS `singleton()`.
     *
     * La closure est évaluée À LA RÉSOLUTION, donc après `ResolveInstitution`.
     * Un singleton capturerait le premier tenant résolu et le rejouerait pour
     * tous les suivants : sous Octane comme en suite PHPUnit, le conteneur
     * survit entre requêtes, et une fuite de singleton ici laisserait un
     * établissement KLASSCI écrire un catalogue local — pas un défaut de
     * performance, une fuite entre établissements (épique #697, art. 3).
     */
    public function register(): void
    {
        $this->app->bind(
            CatalogueAuthority::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)->catalogueForCurrentTenant(),
        );

        // Même règle pour les sources : `bind()`, résolu à chaque demande. Le
        // contrôleur les reçoit par sa MÉTHODE, pas par son constructeur —
        // Laravel garde le contrôleur sur l'objet Route, et une source reçue à
        // la construction resterait celle de la première école servie.
        $this->app->bind(
            TeacherMatieresReader::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(MyMatieresQueryService::class, LocalTeacherMatieresReader::class),
        );
        $this->app->bind(
            MatiereDetailsReader::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(MatiereDetailsQueryService::class, LocalMatiereDetailsReader::class),
        );

        // Lot 2 : les classes. Une seule source locale sert la fiche par ses
        // deux portes et le roster ; elle applique une seule grille d'accès.
        $this->app->bind(
            TeacherClassesReader::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(TeacherClassesQueryService::class, LocalTeacherClassesReader::class),
        );
        $this->app->bind(
            ClasseDetailsReader::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(ClasseDetailsQueryService::class, LocalClasseDetailsReader::class),
        );
        $this->app->bind(
            ClasseLocalDoorReader::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(ClasseLocalDetailsService::class, LocalClasseDetailsReader::class),
        );
        $this->app->bind(
            ClasseEtudiantsReader::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(ClasseEtudiantsQueryService::class, LocalClasseDetailsReader::class),
        );

        // Lot 3a : les classes de l'établissement, pour les écrans de gestion.
        $this->app->bind(
            InstitutionClassesReader::class,
            static fn ($app) => $app->make(RosterAuthorityFactory::class)
                ->sourceForCurrentTenant(KlassciInstitutionClassesReader::class, LocalInstitutionClassesReader::class),
        );
    }
}
