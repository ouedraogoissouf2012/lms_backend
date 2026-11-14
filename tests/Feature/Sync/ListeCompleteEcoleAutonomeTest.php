<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Une école en mode autonome ne suit PAS la liste KLASSCI de ses classes : ses
 * inscriptions sont décidées par l'établissement (import, adhésion par code).
 *
 * Une école d'origine KLASSCI passée en mode autonome garde des classes
 * `klassci_id`, et la synchronisation d'une classe peut encore y être atteinte
 * (séance miroir, enseignant à jeton). Elle n'y désactive rien. Le choix se fait
 * par l'autorité de l'école, à CHAQUE appel (règle 3).
 */
final class ListeCompleteEcoleAutonomeTest extends TestCase
{
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /** 11. Liste complète qui n'a pas un élève inscrit localement : rien ne change. */
    public function test_l_ecole_autonome_ne_suit_pas_la_liste_klassci(): void
    {
        $ecole = $this->ecole();
        app(TenantManager::class)->set($ecole);
        [$classe, $inscrit] = $this->classeEtInscrit($ecole);
        $this->klassciListeVideComplete();

        self::assertNotNull(app(ClasseSyncService::class)->syncClasseById(71, 'jeton'));

        self::assertSame('actif', self::statut($classe, $inscrit));
    }

    /** 12. Le même service sert une école KLASSCI (réconciliée), puis une école autonome (non). */
    public function test_le_meme_service_suit_l_autorite_de_chaque_ecole(): void
    {
        $klassci = $this->ecole(InstitutionMode::Klassci);
        $autonome = $this->ecole();
        [$classeK, $inscritK] = $this->classeEtInscrit($klassci);
        [$classeA, $inscritA] = $this->classeEtInscrit($autonome);
        $this->klassciListeVideComplete();
        $service = app(ClasseSyncService::class);

        app(TenantManager::class)->set($klassci);
        self::assertNotNull($service->syncClasseById(71, 'jeton'));
        app(TenantManager::class)->set($autonome);
        self::assertNotNull($service->syncClasseById(71, 'jeton'));

        self::assertSame('inactif', self::statut($classeK, $inscritK), 'école KLASSCI : la liste fait foi');
        self::assertSame('actif', self::statut($classeA, $inscritA), 'école autonome : rien ne change');
    }

    /**
     * Une classe d'origine KLASSCI (`klassci_id` 71) et un élève qui porte un
     * identifiant KLASSCI, inscrit `actif` : seule l'autorité de l'école le protège.
     *
     * @return array{0: Classe, 1: User}
     */
    private function classeEtInscrit(Institution $ecole): array
    {
        $classe = $this->classe($ecole, '6e A');
        $classe->forceFill(['klassci_id' => 71])->save();
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['klassci_id' => 501])->save();
        $this->inscrire($eleve, $classe, 'actif');

        return [$classe, $eleve];
    }

    /** `classes/71` : une liste vide, prouvée complète (effectif 0). */
    private function klassciListeVideComplete(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->with('jeton', 'classes/71', 'GET')
                ->andReturn(['data' => ['classe' => ['id' => 71, 'libelle' => '6e A', 'places_occupees' => 0], 'etudiants' => []]]);
        });
    }

    private static function statut(Classe $classe, User $eleve): mixed
    {
        return DB::table('classe_etudiant')->where('classe_id', $classe->id)->where('user_id', $eleve->id)->value('statut');
    }
}
