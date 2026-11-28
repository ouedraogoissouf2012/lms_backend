<?php

declare(strict_types=1);

namespace Tests\Feature\Bulletin;

use App\Enums\InstitutionMode;
use App\Models\AppreciationDeBulletin;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1677 (B1) : le modèle de bulletin d'une école, et les appréciations d'une classe pour une
 * période (un intervalle de dates nommé). La gestion écrit le modèle, l'appréciation générale et
 * la décision ; l'enseignant d'une matière, l'appréciation de sa matière. Dans une école KLASSCI,
 * le bulletin reste à KLASSCI (403 motivé). Vraie route, vrai jeton, contrat.
 */
final class ModeleEtAppreciationsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const PERIODE = ['libelle' => '1er trimestre', 'du' => '2026-09-01', 'au' => '2026-12-19'];

    private const MODELE = [
        'entete' => ['République de Côte d\'Ivoire', 'Lycée moderne de test'],
        'signataires' => [['fonction' => 'Le proviseur', 'nom' => 'M. Proviseur']],
        'bareme' => [['min' => 0, 'max' => 9.99, 'libelle' => 'Insuffisant'], ['min' => 10, 'max' => 20, 'libelle' => 'Satisfaisant']],
        'blocs' => [['libelle' => 'Enseignement général', 'matiere_ids' => []]],
        'mentions' => ['rang' => false, 'moyenne_classe' => true, 'assiduite' => true],
    ];

    private Institution $ecole;

    private Classe $classe;

    private Matiere $maths;

    private Matiere $physique;

    private User $profDeMaths;

    private User $awa;

    public function test_la_gestion_ecrit_et_lit_le_modele_de_l_ecole(): void
    {
        $this->jeu();
        $coordinateur = $this->membre($this->ecole, 'coordinateur');

        $this->modele($coordinateur)->assertOk()->assertJsonPath('data.entete', [])->assertJsonPath('data.mentions.rang', true);
        $this->modele($coordinateur, self::MODELE)->assertOk()->assertJsonPath('data.mentions.rang', false);
        $this->modele($this->membre($this->ecole, 'superAdmin'))->assertOk()->assertJsonPath('data.signataires.0.nom', 'M. Proviseur');

        $this->modele($this->profDeMaths, self::MODELE)->assertForbidden();
        $this->modele($this->awa)->assertForbidden();
    }

    public function test_un_bareme_dont_la_borne_haute_precede_la_basse_est_refuse(): void
    {
        $this->jeu();
        $modele = self::MODELE;
        $modele['bareme'] = [['min' => 12, 'max' => 8, 'libelle' => 'Inversé']];

        $this->modele($this->membre($this->ecole, 'coordinateur'), $modele)->assertUnprocessable()->assertJsonValidationErrors('bareme.0.max');
    }

    public function test_chacun_apprecie_ce_qui_est_a_lui(): void
    {
        $this->jeu();
        $coordinateur = $this->membre($this->ecole, 'coordinateur');

        $this->ecrire($this->profDeMaths, $this->maths->id, 'Bon trimestre en calcul.')->assertOk()->assertJsonPath('data.matiere_id', $this->maths->id);
        $this->ecrire($this->profDeMaths, $this->physique->id, 'Pas la sienne')->assertForbidden()->assertJsonPath('reason', 'report_card_subject_not_taught');
        $this->ecrire($this->profDeMaths, null, 'La générale')->assertForbidden()->assertJsonPath('reason', 'report_card_management_only');
        $this->ecrire($coordinateur, null, 'Élève sérieuse.', 'Passe en classe supérieure')->assertOk()->assertJsonPath('data.decision', 'Passe en classe supérieure');
        $this->ecrire($coordinateur, null, 'Élève très sérieuse.', 'Passe en classe supérieure')->assertOk();

        self::assertSame(2, AppreciationDeBulletin::query()->withoutGlobalScope('institution')->count(), 'Écrire remplace l\'appréciation de la même case.');
        self::assertSame([$this->maths->id], array_column((array) $this->lire($this->profDeMaths)->json('data'), 'matiere_id'), 'L\'enseignant lit ses matières.');
        self::assertCount(2, (array) $this->lire($coordinateur)->json('data'));
    }

    public function test_l_eleve_doit_etre_de_la_classe_et_la_periode_separe_les_cases(): void
    {
        $this->jeu();
        $horsClasse = $this->membre($this->ecole, 'etudiant');

        $this->ecrire($this->profDeMaths, $this->maths->id, 'Texte', null, $horsClasse)->assertUnprocessable()->assertJsonPath('reason', 'student_not_in_class');
        $this->ecrire($this->profDeMaths, $this->maths->id, 'Trimestre 1')->assertOk();
        $this->ecrire($this->profDeMaths, $this->maths->id, 'Trimestre 2', null, null, ['libelle' => '2e trimestre', 'du' => '2027-01-05', 'au' => '2027-03-27'])->assertOk();

        self::assertSame(2, AppreciationDeBulletin::query()->withoutGlobalScope('institution')->count());
        self::assertSame(['Trimestre 1'], array_column((array) $this->lire($this->profDeMaths)->json('data'), 'texte'));
    }

    public function test_ecole_klassci_le_bulletin_reste_a_klassci(): void
    {
        $ecole = $this->ecole(InstitutionMode::Klassci);
        $this->disableKlassciMiddleware();

        $this->modele($this->membre($ecole, 'coordinateur'))->assertForbidden()->assertJsonPath('reason', 'report_cards_managed_by_klassci');
    }

    private function jeu(): void
    {
        $this->ecole = $this->ecole();
        $this->classe = $this->classe($this->ecole, '6e A');
        $this->maths = $this->matiere($this->ecole, 'Maths');
        $this->physique = $this->matiere($this->ecole, 'Physique');
        $this->profDeMaths = $this->membre($this->ecole, 'enseignant');
        $this->programme($this->classe, $this->maths, $this->profDeMaths);
        $this->programme($this->classe, $this->physique, $this->membre($this->ecole, 'enseignant'));
        $this->awa = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($this->awa, $this->classe, 'actif');
        $this->disableKlassciMiddleware();
    }

    /** @param  array<string, mixed>|null  $corps */
    private function modele(User $acteur, ?array $corps = null): TestResponse
    {
        $reponse = $corps === null
            ? $this->asTenant($acteur)->getJson('/api/lms/bulletins/modele')
            : $this->asTenant($acteur)->putJson('/api/lms/bulletins/modele', $corps);
        $this->assertMatchesOpenApiResponse($reponse, '/lms/bulletins/modele', $corps === null ? 'get' : 'put');

        return $reponse;
    }

    /** @param  array{libelle: string, du: string, au: string}|null  $periode */
    private function ecrire(User $auteur, ?int $matiere, string $texte, ?string $decision = null, ?User $eleve = null, ?array $periode = null): TestResponse
    {
        $reponse = $this->asTenant($auteur)->putJson('/api/lms/classes/'.$this->classe->id.'/appreciations', [
            'periode' => $periode ?? self::PERIODE, 'eleve_id' => ($eleve ?? $this->awa)->id,
            'matiere_id' => $matiere, 'texte' => $texte, 'decision' => $decision,
        ]);
        $this->assertMatchesOpenApiResponse($reponse, '/lms/classes/{classe}/appreciations', 'put');

        return $reponse;
    }

    private function lire(User $lecteur): TestResponse
    {
        $reponse = $this->asTenant($lecteur)->getJson('/api/lms/classes/'.$this->classe->id.'/appreciations?'.http_build_query(self::PERIODE));
        $this->assertMatchesOpenApiResponse($reponse, '/lms/classes/{classe}/appreciations', 'get');

        return $reponse->assertOk();
    }
}
