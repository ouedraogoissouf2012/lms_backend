<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\InstitutionMode;
use App\Jobs\ProcessImportJob;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1655 B — CARACTÉRISATION de l'import des élèves (#718, #846, #1726), écrite AVANT que le circuit d'import ne se
 * généralise par type. Elle fige, en `assertSame` sur les tableaux ENTIERS, ce que le front (F#699) lit : la forme
 * du rapport d'aperçu, celle de la lecture avant et après confirmation, celle des deux échecs d'analyse, et chaque
 * motif de ligne avec son message.
 *
 * C'est un test VERT par nature : il décrit le comportement actuel, il ne demande rien de nouveau. La généralisation
 * doit le laisser vert SANS le modifier ; s'il doit changer, c'est que le comportement a changé.
 */
final class LImportDesElevesNeChangePasTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ENTETE = "nom;prenom;email;telephone;role;date_inscription;statut\n";

    /** Une ligne par motif de l'aperçu, et deux lignes acceptées. */
    private const LIGNES = [
        'Doe;Jane;Jane@Ex.test;;;;',
        ';Awa;awa@ex.test;;;;',
        'Kaboré;Awa;;;;;',
        'Doe;Jeanne;jane@ex.test;;;;',
        'Ouédraogo;Issa;issa@ex.test;;chef;;',
        'Sawadogo;Ali;ali@ex.test;;supradmin;;',
        'Traoré;Fanta;fanta@ex.test;;admin;;',
        'Zongo;Paul;paul@ex.test;;;31/02/2026;',
        'Zongo;Marie;marie@ex.test;;;demain;',
        'Kaboré;Rasmata;rasmata@ex.test;;;;radié',
        'Kaboré;Ines;ines@ex.test;;;;suspendu',
        'Kaboré;Moussa;moussa@ex.test;;etudiant;01/09/2026;active',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_l_apercu_rend_la_meme_forme_et_les_memes_motifs(): void
    {
        $reponse = $this->apercu($this->enseignant(), self::ENTETE.implode("\n", self::LIGNES)."\n")->assertOk();

        self::assertSame(['import_id', 'rows', 'counts'], array_keys((array) $reponse->json('data')));
        self::assertSame(['ok' => 2, 'error' => 10, 'total' => 12], $reponse->json('data.counts'));
        self::assertSame($this->lignesAttendues(), $reponse->json('data.rows'));
    }

    public function test_la_lecture_avant_et_apres_confirmation_garde_sa_forme(): void
    {
        Queue::fake();
        $enseignant = $this->enseignant();
        $id = (int) $this->apercu($enseignant, self::ENTETE.implode("\n", self::LIGNES)."\n")->json('data.import_id');
        $lignes = array_map(static fn (array $l): array => array_diff_key($l, ['payload' => true]), $this->lignesAttendues());

        $avant = $this->asTenant($enseignant)->getJson("/api/lms/imports/{$id}")->assertOk()->json('data');
        self::assertSame(['import_id' => $id, 'status' => 'previewed', 'counts' => ['ok' => 2, 'error' => 10], 'rows' => $lignes], $avant);

        $this->asTenant($enseignant)->postJson("/api/lms/imports/{$id}/confirm")->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['import_id' => $id, 'status' => 'queued']]);
        app()->call([new ProcessImportJob($id, (int) $enseignant->institution_id), 'handle']);

        $apres = $this->asTenant($enseignant)->getJson("/api/lms/imports/{$id}")->assertOk()->json('data');
        self::assertSame(['import_id' => $id, 'status' => 'done', 'counts' => ['ok' => 2, 'error' => 10], 'rows' => $lignes], $apres);
    }

    public function test_les_deux_echecs_d_analyse_gardent_leur_forme(): void
    {
        $enseignant = $this->enseignant();

        $this->apercu($enseignant, "nom;nom;email\nDoe;Jane;jane@ex.test\n")->assertStatus(422)->assertExactJson([
            'success' => false,
            'code' => 'duplicate_header',
            'message' => 'Deux colonnes du fichier portent le même nom. Renommez-les avant de réessayer.',
        ]);

        $trop = self::ENTETE.implode("\n", array_map(static fn (int $i): string => "Nom{$i};Prenom;e{$i}@ex.test;;;;", range(1, 5001)))."\n";
        $reponse = $this->apercu($enseignant, $trop)->assertOk();
        self::assertSame(['ok' => 5000, 'error' => 1, 'total' => 5001], $reponse->json('data.counts'));
        self::assertSame(['line' => 5002, 'status' => 'error', 'code' => 'too_many_rows', 'message' => 'Plus de 5000 lignes.', 'payload' => null], $reponse->json('data.rows.5000'));
    }

    /** @return list<array{line: int, status: string, code: string|null, message: string|null, payload: array<string, string>|null}> */
    private function lignesAttendues(): array
    {
        $refus = static fn (int $ligne, string $code, string $message): array => ['line' => $ligne, 'status' => 'error', 'code' => $code, 'message' => $message, 'payload' => null];
        $acceptee = static fn (int $ligne, array $payload): array => ['line' => $ligne, 'status' => 'ok', 'code' => null, 'message' => null, 'payload' => $payload];

        return [
            $acceptee(2, ['nom' => 'Doe', 'prenom' => 'Jane', 'email' => 'jane@ex.test', 'telephone' => '', 'code_classe' => '', 'role' => 'etudiant', 'date_inscription' => '', 'statut' => '']),
            $refus(3, 'missing_name', 'Nom et prénom requis.'),
            $refus(4, 'missing_id', 'Email ou téléphone requis.'),
            $refus(5, 'duplicate', 'Doublon dans le fichier.'),
            $refus(6, 'role_inconnu', 'Rôle « chef » inconnu. Valeurs acceptées : etudiant, enseignant, coordinateur, admin.'),
            $refus(7, 'role_plateforme', 'Le rôle « supradmin » gère la plateforme entière et ne s\'attribue pas par import.'),
            $refus(8, 'role_interdit', 'Vous ne pouvez pas créer un compte « admin », plus permissif que le vôtre.'),
            $refus(9, 'date_invalide', 'Date « 31/02/2026 » inexistante au calendrier.'),
            $refus(10, 'date_invalide', 'Date « demain » illisible. Formats acceptés : 31/12/2026 ou 2026-12-31.'),
            $refus(11, 'statut_inconnu', 'Statut « radié » inconnu. Valeurs acceptées : actif, inactif, abandonne.'),
            $refus(12, 'statut_non_importable', 'Statut « suspendu » non importable : aucune règle ne le distingue d\'« inactif ».'),
            $acceptee(13, ['nom' => 'Kaboré', 'prenom' => 'Moussa', 'email' => 'moussa@ex.test', 'telephone' => '', 'code_classe' => '', 'role' => 'etudiant', 'date_inscription' => '2026-09-01', 'statut' => 'actif']),
        ];
    }

    private function apercu(User $compte, string $csv): TestResponse
    {
        return $this->asTenant($compte)->post('/api/lms/imports/preview', [
            'file' => UploadedFile::fake()->createWithContent('eleves.csv', $csv),
        ], ['Accept' => 'application/json']);
    }

    private function enseignant(): User
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        app(TenantManager::class)->set($ecole);

        return User::factory()->teacher()->create(['institution_id' => $ecole->id]);
    }
}
