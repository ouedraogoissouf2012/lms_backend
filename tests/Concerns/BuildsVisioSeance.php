<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use App\Services\Audience\ClasseAudienceSource;
use RuntimeException;

/**
 * Une visio prête pour les contrats d'entrée et de validation (lot B).
 *
 * École créée depuis KLASSCI : la séance porte la classe et l'enseignant KLASSCI,
 * l'élève est inscrit au pivot local de cette classe (vérité de #712). École
 * créée depuis le LMS (`standalone()`, sans URL KLASSCI) : la séance n'a ni classe
 * ni enseignant KLASSCI, son auteur en est l'enseignant. Une telle séance à visio
 * ACTIVE est un état fabriqué : rien ne peut aujourd'hui activer la visio d'une
 * séance sans identifiant KLASSCI. Il fige le refus que l'élève y recevrait.
 */
trait BuildsVisioSeance
{
    private const CLASSE_KLASSCI = 55;

    private const ENSEIGNANT_KLASSCI = 777;

    private function ecole(bool $depuisLeLms): Institution
    {
        return $depuisLeLms ? Institution::factory()->standalone()->create() : Institution::factory()->create();
    }

    private function compte(Institution $ecole, string $role, array $attributs = []): User
    {
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => $role] + $attributs);
    }

    private function enseignantDeLaSeance(Institution $ecole): User
    {
        return $this->compte($ecole, 'enseignant', ['klassci_enseignant_id' => self::ENSEIGNANT_KLASSCI]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function visio(Institution $ecole, ?User $auteur = null, array $attributs = []): Seance
    {
        $depuisLeLms = $ecole->mode === InstitutionMode::Standalone;

        return Seance::factory()->visioActive()->create([
            'institution_id' => $ecole->id,
            'klassci_seance_id' => $depuisLeLms ? null : 12345,
            'klassci_classe_id' => $depuisLeLms ? null : self::CLASSE_KLASSCI,
            'klassci_enseignant_id' => $depuisLeLms ? null : self::ENSEIGNANT_KLASSCI,
            'created_by' => $auteur?->id,
        ] + $attributs);
    }

    private function eleveInscrit(Institution $ecole): User
    {
        $eleve = $this->compte($ecole, 'etudiant');
        Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => self::CLASSE_KLASSCI])
            ->etudiants()->attach($eleve->id, ['statut' => 'actif']);

        return $eleve;
    }

    /** La lecture de l'inscription échoue : le service rattrape et rend son 500. */
    private function inscriptionIllisible(): void
    {
        $this->app->bind(ClasseAudienceSource::class, static fn (): ClasseAudienceSource => new class implements ClasseAudienceSource
        {
            public function containsStudent(Seance $seance, User $student): bool
            {
                throw new RuntimeException('panne provoquée par le test');
            }
        });
    }
}
