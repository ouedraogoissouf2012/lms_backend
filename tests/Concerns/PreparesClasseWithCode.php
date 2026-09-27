<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Enrollment\ClasseEnrolmentCodeService;
use App\Services\TenantManager;

/**
 * Une école autonome, une classe locale et son code d'inscription (#924).
 *
 * Le même harnais était recopié à l'identique dans les trois fichiers des
 * portes d'inscription — `InscriptionParCodeTest`, `RejoindreParCodeTest`,
 * `MotifsDesRefusTest`. Même motif que {@see OpensEvaluationAttempt}.
 */
trait PreparesClasseWithCode
{
    /**
     * Le code est émis par le VRAI service, pas écrit en base : c'est ce qui
     * garantit sa forme (alphabet, normalisation) dans chaque test.
     *
     * @return array{0: Institution, 1: string, 2: Classe}
     */
    protected function classeAvecCode(): array
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        app(TenantManager::class)->set($ecole);

        $classe = Classe::factory()->create([
            'institution_id' => $ecole->getKey(),
            'klassci_id' => null,
        ]);

        $code = app(ClasseEnrolmentCodeService::class)->generer($classe->getKey());

        // Le tenant est ensuite posé par le jeton ou par l'en-tête, comme en
        // production — jamais laissé posé par la préparation.
        app(TenantManager::class)->reset();

        return [$ecole, $code, $classe];
    }

    protected function apprenant(Institution $ecole, string $email = 'awa@test.ci'): User
    {
        return User::factory()->create([
            'institution_id' => $ecole->getKey(),
            'email' => $email,
            'role' => 'etudiant',
        ]);
    }
}
