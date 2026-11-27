<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1629 (#1249), verrou de la garde : au login, le jeton PERSONNEL du compte ne part que si son adresse
 * est EXACTEMENT l'URL de son école. Un compte dont l'adresse est une autre (une école détachée, un
 * hôte X qui n'est pas l'URL Y de l'école) ne reçoit aucune synchronisation : son jeton n'est envoyé
 * nulle part.
 *
 * Sans la garde, entrer dans l'école suffirait à résoudre la cible du TENANT (priorité 3) : le jeton
 * personnel partirait vers l'URL de l'école, qui n'est pas celle qui l'a émis.
 *
 * La vraie route ne peut pas produire ce cas : au login, l'adresse du compte vient de la découverte,
 * qui lit l'URL de l'école. Le synchroniseur est donc appelé directement, avec une autre adresse.
 */
final class LeJetonDuLoginNePartQueVersLEcoleTest extends TestCase
{
    use RefreshDatabase;

    private const ECOLE = 'https://ecole-garde-1629.klassci.test/api/lms';

    private const AUTRE = 'https://autre-garde-1629.klassci.test/api/lms';

    /** @return array<string, array{string}> */
    public static function rolesSynchronises(): array
    {
        return ['élève (classe)' => ['etudiant'], 'enseignant (matières)' => ['enseignant']];
    }

    #[DataProvider('rolesSynchronises')]
    public function test_une_adresse_qui_n_est_pas_celle_de_l_ecole_ne_recoit_aucune_synchronisation(string $role): void
    {
        config(['services.klassci.url' => '']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => []], 200)]);
        $ecole = Institution::factory()->create([
            'is_active' => true,
            'mode' => InstitutionMode::Klassci,
            'klassci_api_url' => self::ECOLE,
        ]);

        app(KlassciUserSynchronizer::class)->sync(
            ['id' => 9301, 'email' => 'garde1629@ecole.test', 'name' => 'Compte 1629', 'role' => $role],
            'jeton-personnel-1629',
            self::AUTRE,
            $ecole,
        );

        Http::assertNothingSent();
    }
}
