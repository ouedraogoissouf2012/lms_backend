<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Catalogue\CatalogueAuthority;
use App\Services\Catalogue\InstitutionStructureReader;
use App\Services\Catalogue\KlassciInstitutionStructureReader;
use App\Services\Catalogue\LocalInstitutionStructureReader;
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
use App\Services\Dashboard\KlassciStudentDashboardReader;
use App\Services\Dashboard\KlassciTeacherDashboardReader;
use App\Services\Dashboard\LocalStudentDashboardReader;
use App\Services\Dashboard\LocalTeacherDashboardReader;
use App\Services\Dashboard\StudentDashboardReader;
use App\Services\Dashboard\TeacherDashboardReader;
use App\Services\Enseignant\InstitutionTeachersReader;
use App\Services\Enseignant\KlassciInstitutionTeachersReader;
use App\Services\Enseignant\LocalInstitutionTeachersReader;
use App\Services\Evaluation\Programming\KlassciProgrammationDesEvaluations;
use App\Services\Evaluation\Programming\LocalProgrammationDesEvaluations;
use App\Services\Evaluation\Programming\ProgrammationDesEvaluationsReader;
use App\Services\Matiere\InstitutionMatieresReader;
use App\Services\Matiere\KlassciInstitutionMatieresReader;
use App\Services\Matiere\LocalInstitutionMatieresReader;
use App\Services\Matiere\LocalMatiereDetailsReader;
use App\Services\Matiere\LocalTeacherMatieresReader;
use App\Services\Matiere\MatiereDetailsQueryService;
use App\Services\Matiere\MatiereDetailsReader;
use App\Services\Matiere\MyMatieresQueryService;
use App\Services\Matiere\TeacherMatieresReader;
use App\Services\Roster\RosterAuthorityFactory;
use App\Services\Seances\LocalSeanceDetailReader;
use App\Services\Seances\LocalSeanceParticipants;
use App\Services\Seances\LocalSeancesListReader;
use App\Services\Seances\SeanceDetailReader;
use App\Services\Seances\SeanceParticipantsReader;
use App\Services\Seances\SeancesListReader;
use App\Services\SeanceDetailQueryService;
use App\Services\SeancesListQueryService;
use Illuminate\Support\ServiceProvider;

/**
 * Liaison de l'autorité sur le catalogue pédagogique (#848), et des sources
 * qui LISENT l'école (école créée depuis le LMS : lot 1, les matières ; lot 2,
 * les classes ; lot 3, les listes de l'établissement et les séances ; R4, le
 * tableau de bord de l'enseignant) : la même
 * route sert les deux types d'école, la fabrique choisit la source.
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
     * Les sources de lecture, port par port : `[source KLASSCI, source locale]`.
     *
     * Une table plutôt qu'une liaison écrite par port : chaque lot y ajoute une
     * ligne, et la règle de liaison (ci-dessous) reste écrite une fois.
     *
     * @var array<class-string, array{class-string, class-string}>
     */
    private const SOURCES = [
        // Lot 1 : les matières de l'enseignant et la fiche matière.
        TeacherMatieresReader::class => [MyMatieresQueryService::class, LocalTeacherMatieresReader::class],
        MatiereDetailsReader::class => [MatiereDetailsQueryService::class, LocalMatiereDetailsReader::class],
        // Lot 2 : les classes. Une seule source locale sert la fiche par ses
        // deux portes et le roster ; elle applique une seule grille d'accès.
        TeacherClassesReader::class => [TeacherClassesQueryService::class, LocalTeacherClassesReader::class],
        ClasseDetailsReader::class => [ClasseDetailsQueryService::class, LocalClasseDetailsReader::class],
        ClasseLocalDoorReader::class => [ClasseLocalDetailsService::class, LocalClasseDetailsReader::class],
        ClasseEtudiantsReader::class => [ClasseEtudiantsQueryService::class, LocalClasseDetailsReader::class],
        // Lot 3a : les classes de l'établissement, pour les écrans de gestion.
        InstitutionClassesReader::class => [KlassciInstitutionClassesReader::class, LocalInstitutionClassesReader::class],
        // Lot 3c : les matières et la structure de l'établissement.
        InstitutionMatieresReader::class => [KlassciInstitutionMatieresReader::class, LocalInstitutionMatieresReader::class],
        InstitutionStructureReader::class => [KlassciInstitutionStructureReader::class, LocalInstitutionStructureReader::class],
        // Lot 3d : les séances de l'utilisateur, et son emploi du temps.
        SeancesListReader::class => [SeancesListQueryService::class, LocalSeancesListReader::class],
        // Lot 3b : les enseignants de l'établissement.
        InstitutionTeachersReader::class => [KlassciInstitutionTeachersReader::class, LocalInstitutionTeachersReader::class],
        // La programmation des évaluations du porteur (`/proxy/evaluations`).
        ProgrammationDesEvaluationsReader::class => [KlassciProgrammationDesEvaluations::class, LocalProgrammationDesEvaluations::class],
        // Les participants autorisés d'une séance (parcours des présences).
        SeanceParticipantsReader::class => [SeanceDetailQueryService::class, LocalSeanceParticipants::class],
        // R4 : le tableau de bord de l'enseignant, composé des sources ci-dessus.
        TeacherDashboardReader::class => [KlassciTeacherDashboardReader::class, LocalTeacherDashboardReader::class],
        // Le tableau de bord de l'élève, composé des lecteurs de l'élève (E2a, E4a).
        StudentDashboardReader::class => [KlassciStudentDashboardReader::class, LocalStudentDashboardReader::class],
        // Le détail d'une séance, sous la règle de la liste du lot 3d.
        SeanceDetailReader::class => [SeanceDetailQueryService::class, LocalSeanceDetailReader::class],
    ];

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
        foreach (self::SOURCES as $port => [$klassci, $local]) {
            $this->app->bind(
                $port,
                static fn ($app) => $app->make(RosterAuthorityFactory::class)->sourceForCurrentTenant($klassci, $local),
            );
        }
    }
}
