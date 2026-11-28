<?php

declare(strict_types=1);

namespace Tests\Feature\Attendances;

use App\Enums\InstitutionMode;
use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1682 (simplifié, ARRÊT 1 bis validé le 27/11) — les heures de l'enseignant se tirent de la visio :
 * - une séance compte sa durée PRÉVUE (fin prévue − début, ou 2 h supposées) si sa visio a été démarrée par SON
 *   enseignant et s'est terminée, à la main ou automatiquement ; sinon 0 ;
 * - qui démarre est retenu au démarrage (`visio_demarree_par_id`) ; une séance d'avant ce relevé compte 0, et la
 *   réponse dit depuis quand les heures sont comptées ;
 * - l'historique du mois de l'enseignant, ses totaux, le rapport de la gestion et son export CSV ;
 * - une école KLASSCI tient ces heures dans KLASSCI (409).
 *
 * Jeton Bearer RÉEL, par `asTenant()` ; horloge figée.
 */
final class HeuresDeLEnseignantTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private Institution $ecole;

    private User $enseignant;

    private User $coordinateur;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        Carbon::setTestNow('2026-12-15 12:00:00');
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->enseignant = $this->membre($this->ecole, 'enseignant');
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
    }

    public function test_demarrer_la_visio_retient_qui_demarre(): void
    {
        $seance = $this->seance('2026-12-15 12:00:00', '2026-12-15 14:00:00', ['visio_enabled' => true, 'visio_status' => 'programmee', 'visio_room_id' => 'salon']);

        $this->asTenant($this->enseignant)->postJson("/api/lms/seances/{$seance->id}/start-visio")->assertOk();

        self::assertSame($this->enseignant->id, $seance->fresh()?->visio_demarree_par_id);
    }

    public function test_l_historique_du_mois_et_ses_totaux(): void
    {
        $comptee = $this->tenue('2026-12-01 08:00:00', '2026-12-01 10:00:00', $this->enseignant);
        $fermeeSeule = $this->tenue('2026-12-02 08:00:00', null, $this->enseignant);
        $parUnAutre = $this->tenue('2026-12-03 08:00:00', '2026-12-03 09:30:00', $this->coordinateur);
        $jamais = $this->seance('2026-12-04 08:00:00', '2026-12-04 10:00:00');
        $enCours = $this->seance('2026-12-05 08:00:00', '2026-12-05 10:00:00', [
            'visio_started_at' => '2026-12-05 08:00:00', 'visio_demarree_par_id' => $this->enseignant->id,
        ]);
        $avantLeReleve = $this->seance('2026-12-06 08:00:00', '2026-12-06 10:00:00', [
            'visio_started_at' => '2026-12-06 08:00:00', 'visio_ended_at' => '2026-12-06 10:00:00',
        ]);
        $this->tenue('2026-11-20 08:00:00', '2026-11-20 10:00:00', $this->enseignant);
        $this->seance('2026-12-20 08:00:00', '2026-12-20 10:00:00');

        $data = $this->asTenant($this->enseignant)->getJson('/api/lms/mes-heures?mois=2026-12')->assertOk()->json('data');

        self::assertSame([
            [$comptee->id, 'comptee', 120],
            [$fermeeSeule->id, 'comptee', 120],
            [$parUnAutre->id, 'demarree_par_un_autre', 0],
            [$jamais->id, 'visio_non_demarree', 0],
            [$enCours->id, 'visio_non_terminee', 0],
            [$avantLeReleve->id, 'auteur_inconnu', 0],
        ], array_map(static fn (array $s): array => [$s['seance_id'], $s['statut'], $s['minutes']], $data['seances']));
        self::assertSame(['minutes' => 240, 'comptees' => 2, 'non_comptees' => 4], $data['totaux']);
        self::assertSame('2026-11-20', $data['depuis']);
    }

    public function test_le_rapport_de_la_gestion_et_son_filtre(): void
    {
        $autre = $this->membre($this->ecole, 'enseignant');
        $this->tenue('2026-12-01 08:00:00', '2026-12-01 10:00:00', $this->enseignant);
        $this->seance('2026-12-02 08:00:00', '2026-12-02 10:00:00');
        $this->tenue('2026-12-03 08:00:00', '2026-12-03 09:00:00', $autre, $autre);

        $data = $this->asTenant($this->coordinateur)->getJson('/api/lms/heures-enseignants?du=2026-12-01&au=2026-12-31')->assertOk()->json('data');

        self::assertSame([
            [$this->enseignant->id, 2, 1, 120],
            [$autre->id, 1, 1, 60],
        ], array_map(static fn (array $e): array => [$e['enseignant_id'], $e['seances'], $e['comptees'], $e['minutes']], $data['enseignants']));

        $filtre = $this->asTenant($this->coordinateur)->getJson("/api/lms/heures-enseignants?du=2026-12-01&au=2026-12-31&enseignant_id={$autre->id}")->json('data.enseignants');
        self::assertSame([$autre->id], array_column($filtre, 'enseignant_id'));
    }

    public function test_l_export_csv(): void
    {
        $this->tenue('2026-12-01 08:00:00', '2026-12-01 10:00:00', $this->enseignant);

        $reponse = $this->asTenant($this->coordinateur)->get('/api/lms/heures-enseignants?du=2026-12-01&au=2026-12-31&format=csv')->assertOk();

        self::assertStringStartsWith('text/csv', (string) $reponse->headers->get('Content-Type'));
        // Le BOM UTF-8 ouvre le fichier : Excel en français lit ainsi les accents des noms.
        $contenu = (string) $reponse->streamedContent();
        self::assertStringStartsWith("\u{FEFF}", $contenu);
        $lignes = preg_split('/\R/', trim(substr($contenu, 3)));
        self::assertSame(['enseignant_id', 'nom', 'seances', 'comptees', 'heures'], str_getcsv((string) ($lignes[0] ?? ''), ';', '"', ''));
        self::assertSame([(string) $this->enseignant->id, $this->enseignant->name, '1', '1', '2,00'], str_getcsv((string) ($lignes[1] ?? ''), ';', '"', ''));
    }

    public function test_un_enseignant_ne_lit_pas_le_rapport_et_un_eleve_pas_ses_heures(): void
    {
        $this->asTenant($this->enseignant)->getJson('/api/lms/heures-enseignants?du=2026-12-01&au=2026-12-31')->assertForbidden();
        $this->asTenant($this->membre($this->ecole, 'etudiant'))->getJson('/api/lms/mes-heures?mois=2026-12')->assertForbidden();
    }

    public function test_les_reponses_suivent_la_spec(): void
    {
        $this->tenue('2026-12-01 08:00:00', '2026-12-01 10:00:00', $this->enseignant);
        $mesHeures = $this->asTenant($this->enseignant)->getJson('/api/lms/mes-heures?mois=2026-12')->assertOk();
        $this->assertMatchesOpenApiResponse($mesHeures, '/lms/mes-heures', 'get');
        $this->assertOpenApiSchemaRefuseSans($mesHeures, '/lms/mes-heures', 'get', 'data');
        $rapport = $this->asTenant($this->coordinateur)->getJson('/api/lms/heures-enseignants?du=2026-12-01&au=2026-12-31')->assertOk();
        $this->assertMatchesOpenApiResponse($rapport, '/lms/heures-enseignants', 'get');
        $refus = $this->asTenant($this->enseignant)->getJson('/api/lms/heures-enseignants?du=2026-12-01&au=2026-12-31')->assertForbidden();
        $this->assertMatchesOpenApiResponse($refus, '/lms/heures-enseignants', 'get');
        $klassci = $this->asTenant($this->membre($this->ecole(InstitutionMode::Klassci), 'enseignant'))->getJson('/api/lms/mes-heures?mois=2026-12');
        $this->assertMatchesOpenApiResponse($klassci->assertStatus(409), '/lms/mes-heures', 'get');
    }

    public function test_une_ecole_klassci_tient_ses_heures_dans_klassci(): void
    {
        $enseignant = $this->membre($this->ecole(InstitutionMode::Klassci), 'enseignant');

        $this->asTenant($enseignant)->getJson('/api/lms/mes-heures?mois=2026-12')
            ->assertStatus(409)->assertJsonPath('reason', 'attendance_managed_by_klassci');
    }

    /** Une séance de l'enseignant dont la visio a été démarrée par `$demarreur` et s'est terminée. */
    private function tenue(string $debut, ?string $fin, User $demarreur, ?User $auteur = null): Seance
    {
        return $this->seance($debut, $fin, [
            'visio_started_at' => $debut,
            'visio_demarree_par_id' => $demarreur->id,
            'visio_ended_at' => Carbon::parse($debut)->addHour(),
        ], $auteur);
    }

    /** @param  array<string, mixed>  $visio */
    private function seance(string $debut, ?string $fin, array $visio = [], ?User $auteur = null): Seance
    {
        $seance = Seance::factory()->for($this->ecole)->create([
            'date_seance' => $debut,
            'fin_seance' => $fin,
            'created_by' => ($auteur ?? $this->enseignant)->id,
            'classe_id' => null,
            'klassci_seance_id' => null,
            'klassci_classe_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_matiere_id' => null,
            'is_active' => true,
        ]);
        $seance->forceFill($visio)->save();

        return $seance;
    }
}
