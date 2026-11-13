<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Visio;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * La bascule de la visio (`toggle-visio`) dans une école en mode autonome
 * (ADR-EI-01, « même route, autre source ») : activer délègue à l'activation
 * locale, désactiver à la désactivation commune. Sans jeton ni appel KLASSCI ;
 * la séance se désigne par son id local. Rien n'est écrit sous un nom `klassci_*`.
 *
 * Vrai jeton porteur (`asTenant`) : la source est choisie par le mode de
 * l'établissement résolu, comme en production.
 */
final class VisioBasculeLocaleTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/lms/seances/{seanceId}/toggle-visio';

    private const KLASSCI = ['klassci_seance_id', 'klassci_enseignant_id', 'klassci_classe_id', 'klassci_matiere_id'];

    private Institution $ecole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        Http::fake();
        Queue::fake();
        $this->ecole = Institution::factory()->standalone()->create();
    }

    public function test_un_coordinateur_active_puis_desactive_la_visio_d_une_seance_locale(): void
    {
        $coordinateur = $this->coordinateur();
        $seance = $this->seanceLocale();

        $active = $this->asTenant($coordinateur)
            ->postJson("/api/lms/seances/{$seance->id}/toggle-visio", ['enabled' => true])
            ->assertOk();
        $this->assertMatchesOpenApiResponse($active, self::CHEMIN, 'post');
        $salon = self::ligne($seance)['visio_room_id'];
        self::assertIsString($salon);
        $active->assertExactJson(['success' => true, 'message' => 'Visioconférence activée avec succès', 'data' => [
            'seance_id' => $seance->id, 'visio_enabled' => true, 'visio_type' => 'jitsi', 'visio_room_id' => $salon,
            'visio_active' => false, 'notifications_sent' => 0, 'notifications_queued' => true,
        ]]);

        $desactive = $this->asTenant($coordinateur)
            ->postJson("/api/lms/seances/{$seance->id}/toggle-visio", ['enabled' => false])
            ->assertOk();
        $this->assertMatchesOpenApiResponse($desactive, self::CHEMIN, 'post');
        $desactive->assertExactJson(['success' => true, 'message' => 'Visioconférence désactivée', 'data' => [
            'seance_id' => $seance->id, 'visio_enabled' => false, 'visio_type' => null, 'visio_room_id' => null,
            'visio_active' => false, 'notifications_sent' => 0, 'notifications_queued' => false,
        ]]);

        self::assertFalse((bool) self::ligne($seance)['visio_enabled']);
        foreach (self::KLASSCI as $colonne) {
            self::assertNull(self::ligne($seance)[$colonne], "{$colonne} reste nul");
        }
        Http::assertNothingSent();
    }

    public function test_l_activation_garde_le_salon_deja_attribue(): void
    {
        $seance = $this->seanceLocale(['visio_room_id' => 'salon-deja-notifie']);

        $this->asTenant($this->coordinateur())
            ->postJson("/api/lms/seances/{$seance->id}/toggle-visio", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.visio_room_id', 'salon-deja-notifie');
    }

    public function test_une_seance_inconnue_est_introuvable_sans_rien_creer(): void
    {
        $reponse = $this->asTenant($this->coordinateur())
            ->postJson('/api/lms/seances/4242/toggle-visio', ['enabled' => true])
            ->assertNotFound();

        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'post');
        self::assertSame(0, DB::table('seances')->count());
    }

    public function test_la_seance_d_un_autre_etablissement_est_introuvable(): void
    {
        $seance = $this->seanceLocale();
        $avant = self::ligne($seance);
        $ailleurs = User::factory()->coordinator()->for(Institution::factory()->standalone()->create())->create(['klassci_token' => null]);

        $this->asTenant($ailleurs)
            ->postJson("/api/lms/seances/{$seance->id}/toggle-visio", ['enabled' => true])
            ->assertNotFound();

        self::assertSame($avant, self::ligne($seance));
    }

    /**
     * Laravel garde le contrôleur sur l'objet Route : une source reçue par le
     * constructeur resterait celle de la première école servie (règle 3).
     */
    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        $ecoleKlassci = Institution::factory()->create();
        $sansJeton = User::factory()->coordinator()->for($ecoleKlassci)->create(['klassci_token' => null]);
        $seance = $this->seanceLocale();

        $this->asTenant($sansJeton)
            ->postJson('/api/lms/seances/4242/toggle-visio', ['enabled' => true])
            ->assertUnauthorized()
            ->assertExactJson(MissingKlassciTokenException::payload());
        $this->asTenant($this->coordinateur())
            ->postJson("/api/lms/seances/{$seance->id}/toggle-visio", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.seance_id', $seance->id);

        Http::assertNothingSent();
    }

    private function coordinateur(): User
    {
        return User::factory()->coordinator()->for($this->ecole)->create(['klassci_token' => null]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function seanceLocale(array $attributs = []): Seance
    {
        return Seance::factory()->for($this->ecole)->create($attributs + [
            'created_by' => User::factory()->teacher()->for($this->ecole)->create(['klassci_token' => null])->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
            'visio_enabled' => false,
            'visio_room_id' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private static function ligne(Seance $seance): array
    {
        return (array) DB::table('seances')->where('id', $seance->id)->first();
    }
}
