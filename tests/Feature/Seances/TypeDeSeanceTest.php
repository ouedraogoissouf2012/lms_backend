<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Enums\TypeDeSeance;
use App\Models\Seance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsVisioSeanceLocale;
use Tests\TestCase;

/**
 * #1668 — une séance porte un type (CM, TD, TP, projet, travail personnel, examen), dans
 * une énumération fermée. Une séance sans type reste à `null` : rien n'est fabriqué.
 *
 * Volet KLASSCI : la charge porte `type_cours` (mesuré en production : « course »). Seules
 * les valeurs sans ambiguïté sont reprises — CM, TD, TP, quelle que soit la casse — toute
 * autre donne `null`.
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class TypeDeSeanceTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsVisioSeanceLocale;
    use RefreshDatabase;

    private User $enseignant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-01 07:00:00');
        $this->disableKlassciMiddleware();
        $this->ecoleAutonome();
        $this->enseignant = $this->compte('teacher');
    }

    public function test_la_creation_ecrit_et_rend_le_type(): void
    {
        $reponse = $this->asTenant($this->enseignant)
            ->postJson('/api/lms/seances', $this->seance(['type_seance' => 'td']))
            ->assertCreated()
            ->assertJsonPath('data.type_seance', 'td');

        $this->assertSame(TypeDeSeance::Td, Seance::query()->findOrFail($reponse->json('data.id'))->type_seance);
    }

    public function test_sans_type_la_seance_reste_a_null(): void
    {
        $reponse = $this->asTenant($this->enseignant)
            ->postJson('/api/lms/seances', $this->seance())
            ->assertCreated()
            ->assertJsonPath('data.type_seance', null);

        $this->assertNull(Seance::query()->findOrFail($reponse->json('data.id'))->type_seance);
    }

    public function test_un_type_hors_de_la_liste_422(): void
    {
        $this->asTenant($this->enseignant)
            ->postJson('/api/lms/seances', $this->seance(['type_seance' => 'atelier']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type_seance');
    }

    public function test_la_modification_change_le_type(): void
    {
        $seance = $this->seanceLocale($this->enseignant, ['date_seance' => '2026-12-02 08:00:00']);

        $this->asTenant($this->enseignant)
            ->patchJson('/api/lms/seances/'.$seance->id, ['type_seance' => 'examen'])
            ->assertOk()
            ->assertJsonPath('data.type_seance', 'examen');
    }

    public function test_la_liste_a_venir_porte_le_type_et_se_filtre_par_type(): void
    {
        $td = $this->seanceLocale($this->enseignant, ['date_seance' => '2026-12-02 08:00:00', 'type_seance' => 'td']);
        $this->seanceLocale($this->enseignant, ['date_seance' => '2026-12-02 14:00:00', 'type_seance' => 'cm']);
        $this->seanceLocale($this->enseignant, ['date_seance' => '2026-12-03 08:00:00']);

        $toutes = $this->asTenant($this->enseignant)->getJson('/api/lms/seances/upcoming?days=7')->assertOk();
        $this->assertSame(['td', 'cm', null], array_column($toutes->json('data'), 'type_seance'));

        $filtrees = $this->asTenant($this->enseignant)->getJson('/api/lms/seances/upcoming?days=7&type=td')->assertOk();
        $this->assertSame([$td->id], array_column($filtrees->json('data'), 'id'));
    }

    public function test_un_filtre_de_type_hors_de_la_liste_422(): void
    {
        $this->asTenant($this->enseignant)
            ->getJson('/api/lms/seances/upcoming?type=atelier')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    /**
     * @return array<string, array{mixed, ?TypeDeSeance}>
     */
    public static function typesKlassci(): array
    {
        return [
            'CM' => ['CM', TypeDeSeance::Cm],
            'td minuscule' => ['td', TypeDeSeance::Td],
            'Tp espaces' => [' Tp ', TypeDeSeance::Tp],
            'course (mesuré en prod)' => ['course', null],
            'projet : ambigu côté KLASSCI' => ['projet', null],
            'vide' => ['', null],
            'absent' => [null, null],
            'pas une chaîne' => [3, null],
        ];
    }

    #[DataProvider('typesKlassci')]
    public function test_klassci_seules_cm_td_tp_sont_reprises(mixed $typeCours, ?TypeDeSeance $attendu): void
    {
        $this->assertSame($attendu, TypeDeSeance::depuisKlassci($typeCours));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function seance(array $extra = []): array
    {
        return $extra + [
            'titre' => 'Séance',
            'date_seance' => '2026-12-02T08:00:00+00:00',
            'classe_id' => $this->classe->id,
        ];
    }
}
