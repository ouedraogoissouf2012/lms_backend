<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Enums\InstitutionMode;
use App\Exceptions\MissingKlassciTokenException;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * Le détail d'une séance d'une école créée depuis le LMS, `GET /lms/seances/{id}/details` :
 * même route, autre source (ADR-EI-01, patron du lot 3d). La séance se désigne par
 * son id LOCAL, sous la MÊME règle de visibilité que la liste des séances : le
 * détail ne montre jamais ce que la liste cache. Aucun appel à KLASSCI.
 *
 * Les participants ne sont lus que pour le personnel, comme dans l'école KLASSCI.
 */
final class SeanceDetailLocaleTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private Institution $ecole;

    private User $auteur;

    private Classe $classe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->auteur = $this->membre($this->ecole, 'enseignant');
        $this->classe = $this->classe($this->ecole, '6e A');
    }

    public function test_l_auteur_lit_le_detail_de_sa_seance_locale_sans_klassci(): void
    {
        $this->aucunAppelKlassci();
        $this->auteur->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($eleve, $this->classe, 'actif');
        $this->inscrire($this->membre($this->ecole, 'etudiant'), $this->classe, 'inactif');
        $maths = $this->matiere($this->ecole, 'Maths');
        $seance = $this->seance(['matiere_id' => $maths->id]);

        $reponse = $this->asTenant($this->auteur)->getJson($this->url($seance))->assertOk();

        $reponse->assertJsonPath('meta', ['source' => 'lms_local'])
            ->assertJsonPath('data.seance.id', $seance->id)
            ->assertJsonPath('data.seance.classe.id', $this->classe->id)
            ->assertJsonPath('data.seance.matiere.libelle', 'Maths')
            ->assertJsonPath('data.seance.enseignant.id', $this->auteur->id)
            ->assertJsonPath('data.seance.heure_fin_estimee', true)
            ->assertJsonPath('data.visio.enabled', true)
            ->assertJsonPath('data.visio.room_id', 'salon')
            ->assertJsonPath('data.participants.teacher.id', $this->auteur->id)
            ->assertJsonPath('data.participants.students', [['id' => $eleve->id, 'nom' => $eleve->name]])
            ->assertJsonPath('data.participants.total', 2);
        self::assertIsArray($reponse->json('data.visio.window'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function gestion(): iterable
    {
        yield 'coordinateur' => ['coordinateur'];
        yield 'superAdmin' => ['superAdmin'];
    }

    #[DataProvider('gestion')]
    public function test_la_gestion_lit_le_detail_avec_les_participants(string $role): void
    {
        $this->aucunAppelKlassci();
        $seance = $this->seance();

        $this->asTenant($this->membre($this->ecole, $role))->getJson($this->url($seance))->assertOk()
            ->assertJsonPath('data.seance.id', $seance->id)
            ->assertJsonPath('data.participants.teacher.id', $this->auteur->id);
    }

    public function test_un_eleve_inscrit_lit_le_detail_sans_les_participants(): void
    {
        $this->aucunAppelKlassci();
        $eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($eleve, $this->classe, 'actif');
        $seance = $this->seance();

        $this->asTenant($eleve)->getJson($this->url($seance))->assertOk()
            ->assertJsonPath('data.seance.id', $seance->id)
            ->assertJsonMissingPath('data.participants');
    }

    /** Ce que la liste cache, le détail le cache : 404, comme une séance inexistante. */
    public function test_ce_que_la_liste_cache_le_detail_le_cache(): void
    {
        $this->aucunAppelKlassci();
        $seance = $this->seance();
        $autreClasse = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($autreClasse, $this->classe($this->ecole, '5e B'), 'actif');
        $inactif = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($inactif, $this->classe, 'inactif');
        $masquant = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($masquant, $this->classe, 'actif');
        SeanceUserHidden::query()->create(['seance_id' => $seance->id, 'user_id' => $masquant->id, 'hidden_at' => now(), 'institution_id' => $this->ecole->id]);

        foreach ([$autreClasse, $inactif, $masquant, $this->membre($this->ecole, 'enseignant')] as $lecteur) {
            $this->asTenant($lecteur)->getJson($this->url($seance))->assertNotFound();
        }
    }

    public function test_la_seance_d_un_autre_etablissement_est_introuvable(): void
    {
        $this->aucunAppelKlassci();
        $seance = $this->seance();
        $ailleurs = $this->membre($this->ecole(), 'coordinateur');

        $this->asTenant($ailleurs)->getJson($this->url($seance))->assertNotFound();
    }

    /**
     * Laravel garde le contrôleur sur l'objet Route : une source reçue par le
     * constructeur resterait celle de la première école servie (règle 3).
     */
    public function test_deux_ecoles_dans_la_meme_methode_recoivent_chacune_leur_source(): void
    {
        $this->aucunAppelKlassci();
        $sansJeton = $this->membre($this->ecole(InstitutionMode::Klassci), 'enseignant');
        $sansJeton->forceFill(['klassci_token' => null])->save();
        $seance = $this->seance();

        $this->asTenant($sansJeton)->getJson('/api/lms/seances/4242/details')
            ->assertUnauthorized()
            ->assertExactJson(MissingKlassciTokenException::payload());
        $this->asTenant($this->auteur)->getJson($this->url($seance))->assertOk()
            ->assertJsonPath('meta.source', 'lms_local');
    }

    /**
     * Une séance locale, telle que `LocalSeanceCreator` l'écrit, à la visio programmée.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function seance(array $attributs = []): Seance
    {
        return Seance::factory()->for($this->ecole)->create($attributs + [
            'created_by' => $this->auteur->id,
            'classe_id' => $this->classe->id,
            'date_seance' => now()->addDay()->setTime(9, 0),
            'is_active' => true,
            'visio_enabled' => true,
            'visio_status' => 'programmee',
            'visio_room_id' => 'salon',
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_classe_id' => null,
            'klassci_matiere_id' => null,
        ]);
    }

    private function url(Seance $seance): string
    {
        return "/api/lms/seances/{$seance->id}/details";
    }

    /** Aucun appel à KLASSCI : ni requête sortante, ni méthode du mandataire. */
    private function aucunAppelKlassci(): void
    {
        Http::preventStrayRequests();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestWithUserToken');
        });
    }
}
