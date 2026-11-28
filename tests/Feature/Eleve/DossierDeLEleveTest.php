<?php

declare(strict_types=1);

namespace Tests\Feature\Eleve;

use App\Enums\InstitutionMode;
use App\Models\AuditLog;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1657 : le dossier de l'élève (identité, matricule), dans une école autonome. Sous vrai jeton.
 *
 * - `PUT /api/eleves/{eleve}/dossier` (gestion) : crée (201) ou met à jour (200). Le matricule est GÉNÉRÉ selon le
 *   gabarit de l'école (`{AAAA}-{NNNN}` par défaut), une séquence par école et par année ; un matricule saisi doit être
 *   unique dans l'école.
 * - `nom` et `prenoms` vivent dans le dossier ; `users.name` en est recalculé (« Prénoms Nom »), pour ce seul compte.
 * - Doublon possible (mêmes nom, prénoms et date de naissance, sans casse ni accents ; ou même téléphone) : 409
 *   `doublon_possible` avec les candidats, sauf `confirmer_doublon`.
 * - Lecture (`GET`) : la gestion et l'élève lui-même voient tout ; un enseignant d'UNE classe de l'élève ne voit que nom,
 *   prénoms, matricule et photo (loi 2013-450, données utiles seulement) ; tout autre compte : 403. Hors de l'école : 404.
 * - École KLASSCI : lecture seule de ce que le LMS reçoit, écriture refusée (403 `catalogue_managed_by_klassci`).
 * - Chaque écriture est tracée à l'audit.
 */
final class DossierDeLEleveTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/eleves/{eleve}/dossier';

    private const IDENTITE = [
        'nom' => 'Konan', 'prenoms' => 'Awa Marie', 'genre' => 'F', 'date_naissance' => '2012-03-14',
        'lieu_naissance' => 'Bouaké', 'nationalite' => 'CI', 'adresse' => 'Rue 12', 'ville' => 'Abidjan',
        'commune' => 'Cocody', 'statut' => 'actif',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->travelTo('2026-11-27 10:00:00');
    }

    public function test_la_gestion_cree_le_dossier_le_matricule_suit_la_sequence_et_le_nom_est_recalcule(): void
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');
        [$awa, $yao] = [$this->membre($ecole, 'etudiant'), $this->membre($ecole, 'etudiant')];

        $premier = $this->ecrire($admin, $awa, self::IDENTITE);
        $second = $this->ecrire($admin, $yao, ['nom' => 'Yao', 'prenoms' => 'Paul'] + self::IDENTITE);

        $premier->assertCreated()->assertJsonPath('data.dossier.matricule', '2026-0001')->assertJsonPath('data.source', 'lms');
        $second->assertCreated()->assertJsonPath('data.dossier.matricule', '2026-0002');
        $this->assertMatchesOpenApiResponse($premier, self::CHEMIN, 'put');
        self::assertSame('Awa Marie Konan', $awa->fresh()?->name);
        self::assertSame('Paul Yao', $yao->fresh()?->name);
    }

    public function test_la_mise_a_jour_garde_le_matricule_et_s_ecrit_a_l_audit(): void
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');
        $awa = $this->membre($ecole, 'etudiant');
        $this->ecrire($admin, $awa, self::IDENTITE)->assertCreated();

        $reponse = $this->ecrire($admin, $awa, ['ville' => 'Yamoussoukro'] + self::IDENTITE);

        $reponse->assertOk()->assertJsonPath('data.dossier.matricule', '2026-0001')->assertJsonPath('data.dossier.ville', 'Yamoussoukro');
        self::assertGreaterThanOrEqual(2, AuditLog::query()->withoutGlobalScopes()->where('auditable_type', 'like', '%DossierEleve%')->count());
    }

    public function test_le_gabarit_de_l_ecole_et_un_matricule_saisi_unique_dans_l_ecole(): void
    {
        $ecole = $this->ecole();
        $ecole->forceFill(['matricule_gabarit' => 'ECO{AA}{NNN}'])->save();
        $admin = $this->membre($ecole, 'admin');

        $this->ecrire($admin, $this->membre($ecole, 'etudiant'), self::IDENTITE)->assertJsonPath('data.dossier.matricule', 'ECO26001');
        $this->ecrire($admin, $this->membre($ecole, 'etudiant'), ['matricule' => 'SAISI-1', 'nom' => 'Deux'] + self::IDENTITE)
            ->assertCreated()->assertJsonPath('data.dossier.matricule', 'SAISI-1');

        $doublon = $this->ecrire($admin, $this->membre($ecole, 'etudiant'), ['matricule' => 'SAISI-1', 'nom' => 'Trois'] + self::IDENTITE);
        $doublon->assertUnprocessable()->assertJsonValidationErrors('matricule');

        $ailleurs = $this->ecole();
        $this->ecrire($this->membre($ailleurs, 'admin'), $this->membre($ailleurs, 'etudiant'), ['matricule' => 'SAISI-1'] + self::IDENTITE)->assertCreated();
    }

    public function test_un_doublon_possible_est_refuse_sauf_confirmation(): void
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');
        $premier = $this->membre($ecole, 'etudiant');
        $this->ecrire($admin, $premier, self::IDENTITE)->assertCreated();
        $second = $this->membre($ecole, 'etudiant');

        // Même identité, à la casse et aux accents près.
        $refus = $this->ecrire($admin, $second, ['nom' => 'KONAN', 'prenoms' => 'awa marié'] + self::IDENTITE);

        $refus->assertStatus(409)->assertJsonPath('reason', 'doublon_possible')
            ->assertJsonPath('candidats.0.eleve_id', $premier->id)
            ->assertJsonPath('candidats.0.matricule', '2026-0001');
        $this->assertMatchesOpenApiResponse($refus, self::CHEMIN, 'put');
        self::assertSame(0, DB::table('dossiers_eleves')->where('user_id', $second->id)->count());

        $this->ecrire($admin, $second, ['nom' => 'KONAN', 'prenoms' => 'awa marié', 'confirmer_doublon' => true] + self::IDENTITE)->assertCreated();
    }

    public function test_un_meme_telephone_est_un_doublon_possible(): void
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');
        $premier = $this->membre($ecole, 'etudiant');
        $premier->forceFill(['phone' => '+2250700000001'])->save();
        $this->ecrire($admin, $premier, self::IDENTITE)->assertCreated();
        $second = $this->membre($ecole, 'etudiant');
        $second->forceFill(['phone' => '+2250700000001'])->save();

        $this->ecrire($admin, $second, ['nom' => 'Autre', 'prenoms' => 'Nom', 'date_naissance' => '2011-01-01'] + self::IDENTITE)
            ->assertStatus(409)->assertJsonPath('reason', 'doublon_possible');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function saisiesRefusees(): array
    {
        return [
            'sans nom' => [['nom' => ''] + self::IDENTITE, 'nom'],
            'sans prénoms' => [['prenoms' => ''] + self::IDENTITE, 'prenoms'],
            'genre inconnu' => [['genre' => 'X'] + self::IDENTITE, 'genre'],
            'naissance future' => [['date_naissance' => '2027-01-01'] + self::IDENTITE, 'date_naissance'],
            'nationalité inconnue' => [['nationalite' => 'ZZ'] + self::IDENTITE, 'nationalite'],
            'statut inconnu' => [['statut' => 'exclu'] + self::IDENTITE, 'statut'],
        ];
    }

    /** @param  array<string, mixed>  $corps */
    #[DataProvider('saisiesRefusees')]
    public function test_422_une_saisie_refusee_n_ecrit_rien(array $corps, string $champ): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');

        $reponse = $this->ecrire($this->membre($ecole, 'admin'), $eleve, $corps);

        $reponse->assertUnprocessable()->assertJsonValidationErrors($champ);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'put');
        self::assertSame(0, DB::table('dossiers_eleves')->count());
    }

    public function test_la_gestion_et_l_eleve_lui_meme_lisent_tout(): void
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');
        $awa = $this->membre($ecole, 'etudiant');
        $this->ecrire($admin, $awa, self::IDENTITE)->assertCreated();

        foreach ([$admin, $this->membre($ecole, 'coordinateur'), $awa] as $lecteur) {
            $lu = $this->lire($lecteur, $awa);
            $lu->assertOk()->assertJsonPath('data.dossier.date_naissance', '2012-03-14')->assertJsonPath('data.dossier.adresse', 'Rue 12');
            $this->assertMatchesOpenApiResponse($lu, self::CHEMIN, 'get');
        }
    }

    public function test_l_enseignant_de_sa_classe_ne_lit_que_nom_prenoms_matricule_et_photo(): void
    {
        $ecole = $this->ecole();
        $awa = $this->membre($ecole, 'etudiant');
        $this->ecrire($this->membre($ecole, 'admin'), $awa, self::IDENTITE)->assertCreated();
        $classe = $this->classe($ecole, '6e A');
        $this->inscrire($awa, $classe, 'actif');
        $sien = $this->membre($ecole, 'enseignant');
        $this->enseigneDans($sien, $classe);

        $lu = $this->lire($sien, $awa);

        $lu->assertOk();
        $this->assertMatchesOpenApiResponse($lu, self::CHEMIN, 'get');
        self::assertSame(['matricule', 'nom', 'photo_url', 'prenoms'], $this->clesTriees((array) $lu->json('data.dossier')));
        $this->lire($this->membre($ecole, 'enseignant'), $awa)->assertForbidden();
        $this->lire($this->membre($ecole, 'etudiant'), $awa)->assertForbidden();
    }

    /** L'isolation : un élève d'une autre école, ou un compte qui n'est pas un élève, est introuvable. */
    public function test_404_hors_de_l_ecole_ou_hors_des_eleves_du_meme_corps_qu_un_id_absent(): void
    {
        $ecole = $this->ecole();
        $admin = $this->membre($ecole, 'admin');
        $ailleurs = $this->membre($this->ecole(), 'etudiant');
        $enseignant = $this->membre($ecole, 'enseignant');

        $absent = $this->asTenant($admin)->getJson('/api/eleves/999999/dossier');
        $absent->assertNotFound();
        foreach ([$ailleurs, $enseignant] as $cible) {
            self::assertSame($absent->json(), $this->lire($admin, $cible)->assertNotFound()->json());
            $this->ecrire($admin, $cible, self::IDENTITE)->assertNotFound();
        }
        self::assertSame(0, DB::table('dossiers_eleves')->count());
    }

    public function test_403_l_enseignant_et_l_eleve_n_ecrivent_pas(): void
    {
        $ecole = $this->ecole();
        $awa = $this->membre($ecole, 'etudiant');

        $this->ecrire($this->membre($ecole, 'enseignant'), $awa, self::IDENTITE)->assertForbidden();
        $this->ecrire($awa, $awa, self::IDENTITE)->assertForbidden();
        self::assertSame(0, DB::table('dossiers_eleves')->count());
    }

    public function test_ecole_klassci_lecture_seule_de_ce_que_le_lms_recoit_et_ecriture_refusee(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $admin = $this->membre($ecole, 'admin');
        $eleve = $this->membre($ecole, 'etudiant');
        $eleve->forceFill(['name' => 'Koffi Jean', 'klassci_data' => ['nom' => 'Koffi', 'prenom' => 'Jean']])->save();

        $lu = $this->lire($admin, $eleve);
        $lu->assertOk()->assertJsonPath('data.source', 'klassci')
            ->assertJsonPath('data.dossier.nom', 'Koffi')->assertJsonPath('data.dossier.prenoms', 'Jean')
            ->assertJsonPath('data.dossier.date_naissance', null);
        $this->assertMatchesOpenApiResponse($lu, self::CHEMIN, 'get');

        $refus = $this->ecrire($admin, $eleve, self::IDENTITE);
        $refus->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
        self::assertSame('Koffi Jean', $eleve->fresh()?->name);
        self::assertSame(0, DB::table('dossiers_eleves')->count());
    }

    public function test_sans_dossier_la_lecture_le_dit(): void
    {
        $ecole = $this->ecole();
        $eleve = $this->membre($ecole, 'etudiant');

        $lu = $this->lire($this->membre($ecole, 'admin'), $eleve);

        $lu->assertOk()->assertJsonPath('data.source', 'lms')->assertJsonPath('data.dossier', null);
        $this->assertMatchesOpenApiResponse($lu, self::CHEMIN, 'get');
    }

    // ───────────────────────── harnais

    /** @param  array<string, mixed>  $corps */
    private function ecrire(User $acteur, User $eleve, array $corps): TestResponse
    {
        return $this->asTenant($acteur)->putJson("/api/eleves/{$eleve->id}/dossier", $corps);
    }

    private function lire(User $acteur, User $eleve): TestResponse
    {
        return $this->asTenant($acteur)->getJson("/api/eleves/{$eleve->id}/dossier");
    }

    private function enseigneDans(User $enseignant, Classe $classe): void
    {
        $matiere = $this->matiere(Institution::query()->findOrFail($classe->institution_id), 'Histoire');
        DB::table('classe_matiere')->insert([
            'classe_id' => $classe->getKey(), 'matiere_id' => $matiere->getKey(), 'enseignant_id' => $enseignant->getKey(),
            'institution_id' => $classe->institution_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $tableau
     * @return list<string>
     */
    private function clesTriees(array $tableau): array
    {
        $cles = array_keys($tableau);
        sort($cles);

        return $cles;
    }
}
