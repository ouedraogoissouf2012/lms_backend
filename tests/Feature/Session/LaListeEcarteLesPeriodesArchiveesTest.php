<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Enums\TrainingSessionStatus;
use App\Models\Institution;
use App\Models\Program;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #1208, point 3 — la liste de gestion des Périodes écarte par défaut les archivées et
 * les purgées. `?archivees=1` fait revenir les archivées ; une purgée ne revient
 * JAMAIS : ce n'est pas une décision humaine, c'est la fin d'une rétention.
 *
 * Les deux volets : école autonome (la liste filtre) et école KLASSCI (la porte du
 * catalogue refuse avant toute lecture, #1184). Vrai jeton porteur.
 */
final class LaListeEcarteLesPeriodesArchiveesTest extends TestCase
{
    use RefreshDatabase;

    private const PERIODES = '/api/training-sessions';

    public function test_par_defaut_la_liste_ecarte_les_archivees_et_les_purgees(): void
    {
        $responsable = $this->responsable(Institution::factory()->standalone()->create());
        $this->unePeriodeParStatut($responsable);

        $reponse = $this->withToken($this->jeton($responsable))->getJson(self::PERIODES)
            ->assertOk()
            ->assertJsonPath('meta.total', 4);

        self::assertSame(['annulee', 'brouillon', 'cloturee', 'publiee'], $this->statuts($reponse->json('data')));
    }

    public function test_archivees_1_fait_revenir_les_archivees_jamais_les_purgees(): void
    {
        $responsable = $this->responsable(Institution::factory()->standalone()->create());
        $this->unePeriodeParStatut($responsable);

        $reponse = $this->withToken($this->jeton($responsable))->getJson(self::PERIODES.'?archivees=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 5);

        self::assertSame(['annulee', 'archivee', 'brouillon', 'cloturee', 'publiee'], $this->statuts($reponse->json('data')));
    }

    public function test_archivees_0_vaut_le_defaut(): void
    {
        $responsable = $this->responsable(Institution::factory()->standalone()->create());
        $this->unePeriodeParStatut($responsable);

        $this->withToken($this->jeton($responsable))->getJson(self::PERIODES.'?archivees=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 4);
    }

    /** @return iterable<string, array{string}> */
    public static function valeursRefusees(): iterable
    {
        yield 'texte' => ['abc'];
        yield 'entier hors booléen' => ['2'];
    }

    #[DataProvider('valeursRefusees')]
    public function test_une_valeur_non_booleenne_est_refusee(string $valeur): void
    {
        $responsable = $this->responsable(Institution::factory()->standalone()->create());

        $this->withToken($this->jeton($responsable))->getJson(self::PERIODES.'?archivees='.$valeur)
            ->assertStatus(422)
            ->assertJsonValidationErrors('archivees');
    }

    /** École KLASSCI : la porte du catalogue refuse avant toute lecture, filtre ou non. */
    public function test_ecole_klassci_la_porte_refuse_avec_ou_sans_le_filtre(): void
    {
        $responsable = $this->responsable(Institution::factory()->create());

        foreach ([self::PERIODES, self::PERIODES.'?archivees=1'] as $route) {
            $this->withToken($this->jeton($responsable))->getJson($route)
                ->assertForbidden()
                ->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        }
    }

    private function unePeriodeParStatut(User $responsable): void
    {
        $programme = Program::factory()->create(['institution_id' => $responsable->institution_id]);

        foreach (TrainingSessionStatus::cases() as $statut) {
            TrainingSession::factory()->create([
                'institution_id' => $responsable->institution_id,
                'program_id' => $programme->getKey(),
                'status' => $statut,
            ]);
        }
    }

    /** @return list<string> */
    private function statuts(mixed $data): array
    {
        self::assertIsArray($data);
        $statuts = array_map(static fn (mixed $periode): string => is_array($periode) && is_string($periode['status'] ?? null) ? $periode['status'] : '', $data);
        sort($statuts);

        return array_values($statuts);
    }

    private function responsable(Institution $ecole): User
    {
        return User::factory()->create(['institution_id' => $ecole->getKey(), 'role' => 'superAdmin']);
    }

    private function jeton(User $user): string
    {
        return $user->createToken('liste-1208')->plainTextToken;
    }
}
