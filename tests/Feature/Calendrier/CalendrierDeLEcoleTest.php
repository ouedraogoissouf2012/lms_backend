<?php

declare(strict_types=1);

namespace Tests\Feature\Calendrier;

use App\Enums\ClasseEtudiantStatut;
use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\TestCase;

/**
 * #1671 (ARRÊT 1 validé le 28/11) — le calendrier de l'année d'une école :
 * - un événement porte un type d'une énumération fermée, un titre, un début et une fin (des JOURS), et une portée :
 *   toute l'école (aucune classe), ou des classes choisies ;
 * - la gestion crée, modifie et supprime ; tout membre de l'école lit, sur une plage ;
 * - la portée limite aussi la LECTURE (Q3) : un élève ou un enseignant ne lit que l'école entière et SES classes ; la
 *   gestion lit tout ;
 * - les DEUX volets (Q1) : KLASSCI n'expose aucun calendrier, l'école KLASSCI compose le sien ici.
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class CalendrierDeLEcoleTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;

    private const RACINE = '/api/lms/calendrier';

    private const ANNEE = ['du' => '2026-09-01', 'au' => '2027-07-31'];

    private Institution $ecole;

    private User $coordinateur;

    private Classe $classeA;

    private Classe $classeB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-28 10:00:00');
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
        $this->classeA = $this->classe($this->ecole, 'Terminale A');
        $this->classeB = $this->classe($this->ecole, 'Terminale B');
    }

    public function test_la_gestion_publie_un_evenement_de_toute_l_ecole(): void
    {
        $this->creer(['type' => 'vacances', 'titre' => 'Vacances de Noël', 'debut' => '2026-12-19', 'fin' => '2027-01-04'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'vacances')
            ->assertJsonPath('data.titre', 'Vacances de Noël')
            ->assertJsonPath('data.debut', '2026-12-19')
            ->assertJsonPath('data.fin', '2027-01-04')
            ->assertJsonPath('data.portee', 'ecole')
            ->assertJsonPath('data.classe_ids', []);
    }

    public function test_un_evenement_peut_viser_des_classes_choisies(): void
    {
        $this->creer(['type' => 'examens', 'titre' => 'Bac blanc', 'debut' => '2027-03-01', 'fin' => '2027-03-05', 'classe_ids' => [$this->classeA->id]])
            ->assertCreated()
            ->assertJsonPath('data.portee', 'classes')
            ->assertJsonPath('data.classe_ids', [$this->classeA->id]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function saisiesRefusees(): array
    {
        return [
            'type hors énumération' => [['type' => 'excursion'], 'type'],
            'fin avant le début' => [['debut' => '2027-03-05', 'fin' => '2027-03-01'], 'fin'],
            'titre absent' => [['titre' => ''], 'titre'],
        ];
    }

    /** @param  array<string, mixed>  $champs */
    #[DataProvider('saisiesRefusees')]
    public function test_une_saisie_invalide_est_refusee(array $champs, string $champ): void
    {
        $this->creer($champs + ['type' => 'autre', 'titre' => 'X', 'debut' => '2027-03-01', 'fin' => '2027-03-01'])
            ->assertUnprocessable()->assertJsonValidationErrors([$champ]);
    }

    public function test_une_classe_d_une_autre_ecole_est_refusee(): void
    {
        $etrangere = $this->classe($this->ecole(), 'Ailleurs');

        $this->creer(['type' => 'autre', 'titre' => 'X', 'debut' => '2027-03-01', 'fin' => '2027-03-01', 'classe_ids' => [$etrangere->id]])
            ->assertUnprocessable()->assertJsonPath('reason', 'classe_hors_ecole');
    }

    public function test_la_portee_limite_la_lecture(): void
    {
        $ecole = $this->evenement('Rentrée', []);
        $deA = $this->evenement('Bac blanc A', [$this->classeA->id]);
        $deB = $this->evenement('Sortie B', [$this->classeB->id]);
        $eleveDeA = $this->eleveDe($this->classeA);
        $eleveDeB = $this->eleveDe($this->classeB);
        $enseignantDeA = $this->membre($this->ecole, 'enseignant');
        $this->programme($this->classeA, $this->matiere($this->ecole, 'Maths'), $enseignantDeA);

        self::assertSame([$ecole, $deA], $this->lus($eleveDeA));
        self::assertSame([$ecole, $deB], $this->lus($eleveDeB), 'L\'élève d\'une autre classe ne lit pas l\'événement de A.');
        self::assertSame([$ecole, $deA], $this->lus($enseignantDeA));
        self::assertSame([$ecole, $deA, $deB], $this->lus($this->coordinateur));
    }

    public function test_un_eleve_dont_l_inscription_n_est_pas_active_ne_lit_que_l_ecole(): void
    {
        $ecole = $this->evenement('Rentrée', []);
        $this->evenement('Bac blanc A', [$this->classeA->id]);
        $eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($eleve, $this->classeA, ClasseEtudiantStatut::Suspendu->value);

        self::assertSame([$ecole], $this->lus($eleve));
    }

    public function test_la_lecture_suit_la_plage(): void
    {
        $chevauche = $this->evenement('Vacances', [], '2026-12-19', '2027-01-04');
        $this->evenement('Fête de fin d\'année', [], '2027-06-28', '2027-06-30');

        $lus = $this->asTenant($this->coordinateur)->getJson(self::RACINE.'?du=2027-01-01&au=2027-01-31')->assertOk()->json('data');

        self::assertSame([$chevauche], array_column($lus, 'id'));
    }

    public function test_la_plage_est_exigee_et_bornee(): void
    {
        $this->asTenant($this->coordinateur)->getJson(self::RACINE)->assertUnprocessable()->assertJsonValidationErrors(['du', 'au']);
        $this->asTenant($this->coordinateur)->getJson(self::RACINE.'?du=2026-01-01&au=2028-01-01')
            ->assertUnprocessable()->assertJsonValidationErrors(['au']);
    }

    public function test_la_gestion_modifie_et_supprime(): void
    {
        $id = $this->evenement('Rentrée', []);

        $this->asTenant($this->coordinateur)->putJson(self::RACINE."/{$id}", [
            'type' => 'rentree', 'titre' => 'Rentrée décalée', 'debut' => '2026-09-08', 'fin' => '2026-09-08', 'classe_ids' => [$this->classeB->id],
        ])->assertOk()->assertJsonPath('data.titre', 'Rentrée décalée')->assertJsonPath('data.classe_ids', [$this->classeB->id]);

        $this->asTenant($this->coordinateur)->deleteJson(self::RACINE."/{$id}")->assertOk();
        self::assertSame([], $this->lus($this->coordinateur));
    }

    public function test_un_enseignant_ne_publie_pas(): void
    {
        $this->asTenant($this->membre($this->ecole, 'enseignant'))->postJson(self::RACINE, [
            'type' => 'autre', 'titre' => 'X', 'debut' => '2027-03-01', 'fin' => '2027-03-01',
        ])->assertForbidden();
    }

    public function test_un_evenement_d_une_autre_ecole_est_introuvable(): void
    {
        $id = $this->evenement('Rentrée', []);
        $autre = $this->membre($this->ecole(), 'coordinateur');

        $this->asTenant($autre)->putJson(self::RACINE."/{$id}", ['type' => 'autre', 'titre' => 'X', 'debut' => '2027-03-01', 'fin' => '2027-03-01'])
            ->assertNotFound();
        $this->asTenant($autre)->deleteJson(self::RACINE."/{$id}")->assertNotFound();
        self::assertSame([], $this->lus($autre));
    }

    public function test_une_ecole_klassci_compose_aussi_son_calendrier(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $coordinateur = $this->membre($ecole, 'coordinateur');

        $this->asTenant($coordinateur)->postJson(self::RACINE, ['type' => 'fermeture', 'titre' => 'Fermeture', 'debut' => '2027-05-01', 'fin' => '2027-05-01'])
            ->assertCreated();
        self::assertCount(1, $this->lus($coordinateur));
    }

    /** @param  array<string, mixed>  $champs */
    private function creer(array $champs): TestResponse
    {
        return $this->asTenant($this->coordinateur)->postJson(self::RACINE, $champs);
    }

    /** @param  list<int>  $classes */
    private function evenement(string $titre, array $classes, string $debut = '2026-09-07', string $fin = '2026-09-07'): int
    {
        return (int) $this->creer(['type' => 'autre', 'titre' => $titre, 'debut' => $debut, 'fin' => $fin, 'classe_ids' => $classes])
            ->assertCreated()->json('data.id');
    }

    /** @return list<int> les événements lus sur l'année, par id croissant */
    private function lus(User $lecteur): array
    {
        $ids = array_map('intval', array_column(
            $this->asTenant($lecteur)->getJson(self::RACINE.'?'.http_build_query(self::ANNEE))->assertOk()->json('data'),
            'id',
        ));
        sort($ids);

        return $ids;
    }

    private function eleveDe(Classe $classe): User
    {
        $eleve = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($eleve, $classe, ClasseEtudiantStatut::Actif->value);

        return $eleve;
    }
}
