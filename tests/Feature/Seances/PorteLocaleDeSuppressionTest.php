<?php

declare(strict_types=1);

namespace Tests\Feature\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1154, ADR-1154-01 (étape A de la pile ADR-760-01) — `DELETE
 * /api/lms/seances/local/{seanceId}` désigne la séance par son id LOCAL, et
 * seulement par lui, dans l'école du compte, dans les deux types d'école.
 *
 * Dans une école KLASSCI, la porte historique `DELETE /api/lms/seances/{id}`
 * reçoit deux espaces et tranche « le local d'abord » (#933). La porte locale
 * est l'espace unique de l'historique : un id qui n'est qu'un
 * `klassci_seance_id` n'y désigne rien.
 *
 * Mêmes gardes et même contrat que la porte historique : le personnel, l'auteur
 * pour un enseignant, 403 sur une séance inconnue ou d'une autre école. Vrai
 * jeton ; aucun id codé en dur, les ids KLASSCI pris au-delà des ids locaux.
 */
final class PorteLocaleDeSuppressionTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private Institution $ecole;

    /** @return iterable<string, array{0: string}> */
    public static function volets(): iterable
    {
        yield 'ecole klassci' => ['klassci'];
        yield 'ecole autonome' => ['standalone'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
        Queue::fake();
    }

    #[DataProvider('volets')]
    public function test_la_porte_locale_supprime_la_seance_de_son_id_local(string $volet): void
    {
        $this->ecole($volet);
        $seance = $this->seance();

        $this->asTenant($this->coordinateur())
            ->deleteJson("/api/lms/seances/local/{$seance->id}")
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Séance supprimée avec succès']);

        self::assertSoftDeleted($seance);
    }

    public function test_dans_une_ecole_klassci_la_collision_ne_touche_que_la_seance_designee(): void
    {
        $this->ecole('klassci');
        $designee = $this->seance();
        $homonyme = $this->seance();
        $homonyme->forceFill(['klassci_seance_id' => $designee->id])->save();
        $avant = self::ligne($homonyme);

        $this->asTenant($this->coordinateur())
            ->deleteJson("/api/lms/seances/local/{$designee->id}")
            ->assertOk();

        self::assertSoftDeleted($designee);
        self::assertSame($avant, self::ligne($homonyme));
    }

    public function test_dans_une_ecole_klassci_un_id_qui_n_est_qu_un_klassci_seance_id_ne_designe_rien(): void
    {
        // La porte historique, elle, le traduit (`Seance::localIdFor`) : c'est la
        // différence entre les deux portes.
        $this->ecole('klassci');
        $miroir = $this->seance();
        $miroir->forceFill(['klassci_seance_id' => (int) DB::table('seances')->max('id') + 1000])->save();
        $avant = self::ligne($miroir);

        $this->asTenant($this->coordinateur())
            ->deleteJson("/api/lms/seances/local/{$miroir->klassci_seance_id}")
            ->assertForbidden();

        self::assertSame($avant, self::ligne($miroir));
    }

    #[DataProvider('volets')]
    public function test_la_seance_d_une_autre_ecole_n_est_pas_supprimee(string $volet): void
    {
        $this->ecole($volet);
        $coordinateur = $this->coordinateur();
        $this->ecole($volet);
        $ailleurs = $this->seance();
        $avant = self::ligne($ailleurs);

        $this->asTenant($coordinateur)
            ->deleteJson("/api/lms/seances/local/{$ailleurs->id}")
            ->assertForbidden();

        self::assertSame($avant, self::ligne($ailleurs));
    }

    #[DataProvider('volets')]
    public function test_un_enseignant_qui_n_en_est_pas_l_auteur_ne_la_supprime_pas(string $volet): void
    {
        $this->ecole($volet);
        $seance = $this->seance();
        $avant = self::ligne($seance);

        $this->asTenant($this->compte('teacher'))
            ->deleteJson("/api/lms/seances/local/{$seance->id}")
            ->assertForbidden();

        self::assertSame($avant, self::ligne($seance));
    }

    #[DataProvider('volets')]
    public function test_un_eleve_ne_supprime_aucune_seance(string $volet): void
    {
        $this->ecole($volet);
        $seance = $this->seance();
        $avant = self::ligne($seance);

        $this->asTenant($this->compte('student'))
            ->deleteJson("/api/lms/seances/local/{$seance->id}")
            ->assertForbidden();

        self::assertSame($avant, self::ligne($seance));
    }

    private function ecole(string $volet): void
    {
        $this->ecole = $volet === 'standalone'
            ? Institution::factory()->standalone()->create()
            : Institution::factory()->create();
    }

    private function coordinateur(): User
    {
        return $this->compte('coordinator');
    }

    private function compte(string $etat): User
    {
        return User::factory()->{$etat}()->for($this->ecole)->create(['klassci_token' => null]);
    }

    /** Une séance de l'école, visio programmée, sans identité KLASSCI. */
    private function seance(): Seance
    {
        return Seance::factory()->for($this->ecole)->withVisio()->create([
            'created_by' => $this->compte('teacher')->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private static function ligne(Seance $seance): array
    {
        return (array) DB::table('seances')->where('id', $seance->id)->first();
    }
}
