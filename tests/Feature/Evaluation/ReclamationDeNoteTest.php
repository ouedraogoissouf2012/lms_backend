<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1678 : la réclamation d'une note. Awa conteste son 8 ; l'enseignant propose 12 ; la gestion
 * tranche. Chaque étape est notifiée ; la note corrigée est celle qui compte ; dans une école
 * KLASSCI, elle reste « à renvoyer » tant que KLASSCI ne l'a pas reçue, même après un refus.
 * Vraie route, vrai jeton, contrat.
 */
final class ReclamationDeNoteTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private Institution $ecole;

    private Classe $classe;

    private User $enseignant;

    private User $awa;

    private Evaluation $evaluation;

    private EvaluationSubmission $copie;

    public function test_le_circuit_complet_corrige_la_note_et_notifie_chaque_etape(): void
    {
        $this->jeu();
        $coordinateur = $this->membre($this->ecole, 'coordinateur');

        $id = (int) $this->deposer($this->awa)->assertCreated()->assertJsonPath('data.statut', 'deposee')->assertJsonPath('data.note_contestee', 8)->json('data.id');
        $this->avis($this->enseignant, $id, ['avis' => 'corriger', 'note_proposee' => 12, 'commentaire' => 'Question 2 mal lue'])->assertOk()->assertJsonPath('data.statut', 'avis_donne');
        $this->decision($coordinateur, $id, ['decision' => 'corrigee', 'note_decidee' => 12])->assertOk()
            ->assertJsonPath('data.statut', 'tranchee')->assertJsonPath('data.decision.note_decidee', 12)->assertJsonPath('data.klassci', null);

        self::assertSame('12.00', $this->copie->fresh()?->getRawOriginal('note_sur_20'));
        self::assertSame([
            Notification::TYPE_RECLAMATION_DEPOSEE => [$this->enseignant->id],
            Notification::TYPE_RECLAMATION_A_TRANCHER => [$coordinateur->id],
            Notification::TYPE_RECLAMATION_TRANCHEE => [$this->awa->id],
        ], $this->notifiees());
        $this->lister($coordinateur)->assertJsonPath('data.compteurs.corrigees_30j', 1)->assertJsonPath('data.reclamations.0.id', $id);
        $this->lister($this->awa)->assertJsonPath('data.reclamations.0.decision.decision', 'corrigee');
    }

    public function test_une_note_maintenue_ne_change_pas(): void
    {
        $this->jeu();
        $id = (int) $this->deposer($this->awa)->json('data.id');
        $this->avis($this->enseignant, $id, ['avis' => 'maintenir'])->assertOk();

        $this->decision($this->membre($this->ecole, 'superAdmin'), $id, ['decision' => 'maintenue'])->assertOk()->assertJsonPath('data.decision.decision', 'maintenue');

        self::assertSame('8.00', $this->copie->fresh()?->getRawOriginal('note_sur_20'));
    }

    public function test_le_delai_et_la_reclamation_deja_ouverte(): void
    {
        $this->jeu();
        $this->ecole->forceFill(['settings' => ['delai_reclamation_jours' => 3]])->save();
        $this->copie->forceFill(['graded_at' => now()->subDays(5)])->save();

        $this->deposer($this->awa)->assertUnprocessable()->assertJsonPath('reason', 'grade_claim_too_late');

        $this->copie->forceFill(['graded_at' => now()->subDay()])->save();
        $this->deposer($this->awa)->assertCreated();
        $this->deposer($this->awa)->assertStatus(409)->assertJsonPath('reason', 'grade_claim_already_open');
    }

    public function test_chacun_son_etape_et_rien_hors_de_sa_portee(): void
    {
        $this->jeu();
        $binta = $this->membre($this->ecole, 'etudiant');
        $this->inscrire($binta, $this->classe, 'actif');
        $id = (int) $this->deposer($this->awa)->json('data.id');
        $coordinateur = $this->membre($this->ecole, 'coordinateur');

        self::assertSame([
            'Binta réclame sans copie' => 404,
            'la gestion tranche avant l avis' => 409,
            'un autre enseignant donne son avis' => 403,
            'l élève donne son avis' => 403,
            'le coordinateur donne l avis' => 403,
        ], [
            'Binta réclame sans copie' => $this->deposer($binta)->status(),
            'la gestion tranche avant l avis' => $this->decision($coordinateur, $id, ['decision' => 'maintenue'])->status(),
            'un autre enseignant donne son avis' => $this->avis($this->membre($this->ecole, 'enseignant'), $id, ['avis' => 'maintenir'])->status(),
            'l élève donne son avis' => $this->avis($this->awa, $id, ['avis' => 'maintenir'])->status(),
            'le coordinateur donne l avis' => $this->avis($coordinateur, $id, ['avis' => 'maintenir'])->status(),
        ]);
        $this->avis($this->enseignant, $id, ['avis' => 'maintenir'])->assertOk();
        self::assertSame([
            'l enseignant tranche' => 403,
            // 403 sur lms aujourd'hui ; 404 quand #1782 (PR #1792) sera fusionnée.
            'la gestion d une autre école' => 403,
        ], [
            'l enseignant tranche' => $this->decision($this->enseignant, $id, ['decision' => 'maintenue'])->status(),
            'la gestion d une autre école' => $this->decision($this->membre($this->ecole(), 'coordinateur'), $id, ['decision' => 'maintenue'])->status(),
        ]);
        $this->lister($binta)->assertJsonPath('data.reclamations', []);
    }

    public function test_ecole_klassci_la_note_corrigee_reste_a_renvoyer_meme_apres_un_refus(): void
    {
        $this->jeu(InstitutionMode::Klassci);
        // L'adresse de KLASSCI est celle de l'école, et le jeton de l'enseignant n'y part que si c'est la sienne.
        $this->ecole->forceFill(['klassci_api_url' => 'https://klassci.test'])->save();
        $this->enseignant->forceFill(['klassci_enseignant_id' => 42, 'klassci_token' => 'jeton-1678', 'klassci_tenant_url' => 'https://klassci.test'])->save();
        $this->evaluation->forceFill(['klassci_enseignant_id' => 42, 'klassci_evaluation_id' => 7777])->save();
        $this->copie->forceFill(['synced_to_klassci' => true])->save();
        $coordinateur = $this->membre($this->ecole, 'coordinateur');
        $id = (int) $this->deposer($this->awa)->json('data.id');
        $this->avis($this->enseignant, $id, ['avis' => 'corriger', 'note_proposee' => 12])->assertOk();

        $this->decision($coordinateur, $id, ['decision' => 'corrigee', 'note_decidee' => 12])->assertOk()->assertJsonPath('data.klassci', 'a_renvoyer');
        Http::fake(['*' => Http::sequence()
            ->push(['success' => false, 'message' => 'Période close'], 422)
            ->push(['success' => true, 'saved' => 1], 200)]);
        $this->asTenant($this->enseignant)->postJson('/api/evaluations/'.$this->evaluation->id.'/sync-klassci');

        $this->lister($coordinateur)->assertJsonPath('data.reclamations.0.statut', 'tranchee')
            ->assertJsonPath('data.reclamations.0.klassci', 'a_renvoyer')->assertJsonPath('data.compteurs.a_renvoyer_a_klassci', 1);

        $this->asTenant($this->enseignant)->postJson('/api/evaluations/'.$this->evaluation->id.'/sync-klassci')->assertOk();
        $this->lister($coordinateur)->assertJsonPath('data.reclamations.0.klassci', 'a_jour')->assertJsonPath('data.compteurs.a_renvoyer_a_klassci', 0);
    }

    private function jeu(InstitutionMode $mode = InstitutionMode::Standalone): void
    {
        $this->ecole = $this->ecole($mode);
        $this->classe = $this->classe($this->ecole, '6e A');
        $maths = $this->matiere($this->ecole, 'Maths');
        $this->programme($this->classe, $maths, null);
        $this->enseignant = $this->membre($this->ecole, 'enseignant');
        $this->evaluation = Evaluation::factory()->create([
            'institution_id' => $this->ecole->id, 'classe_id' => $this->classe->id, 'classe_nom' => $this->classe->libelle,
            'matiere_id' => $maths->id, 'matiere_nom' => $maths->libelle, 'enseignant_id' => $this->enseignant->id,
            'klassci_evaluation_id' => null, 'klassci_classe_id' => null, 'klassci_matiere_id' => null, 'klassci_enseignant_id' => null,
            'is_published' => true, 'show_results' => true, 'status' => 'terminee', 'date_evaluation' => now()->subDays(2), 'duree_minutes' => 60,
            'bareme' => 20, 'coefficient' => '1.00',
        ]);
        EvaluationQuestion::factory()->create(['evaluation_id' => $this->evaluation->id, 'type' => 'qcm', 'options' => ['a', 'b'], 'correct_answers' => ['a']]);
        $this->awa = $this->membre($this->ecole, 'etudiant');
        $this->awa->forceFill(['name' => 'Awa'])->save();
        $this->inscrire($this->awa, $this->classe, 'actif');
        $this->copie = EvaluationSubmission::factory()->create([
            'evaluation_id' => $this->evaluation->id, 'institution_id' => $this->ecole->id, 'student_id' => $this->awa->id,
            'attempt' => 1, 'status' => 'corrige', 'feedback' => null, 'score' => '8.00', 'note_sur_20' => '8.00',
            'submitted_at' => now()->subDay(), 'graded_at' => now()->subDay(), 'synced_to_klassci' => false,
        ]);
        $this->disableKlassciMiddleware();
    }

    private function deposer(User $eleve): TestResponse
    {
        $reponse = $this->asTenant($eleve)->postJson('/api/evaluations/'.$this->evaluation->id.'/reclamations', ['motif' => 'Ma réponse à la question 2 est juste.']);
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/reclamations', 'post');

        return $reponse;
    }

    /** @param  array<string, mixed>  $corps */
    private function avis(User $acteur, int $id, array $corps): TestResponse
    {
        $reponse = $this->asTenant($acteur)->postJson('/api/evaluations/'.$this->evaluation->id.'/reclamations/'.$id.'/avis', $corps);
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/reclamations/{reclamation}/avis', 'post');

        return $reponse;
    }

    /** @param  array<string, mixed>  $corps */
    private function decision(User $acteur, int $id, array $corps): TestResponse
    {
        $reponse = $this->asTenant($acteur)->postJson('/api/evaluations/'.$this->evaluation->id.'/reclamations/'.$id.'/decision', $corps);
        $this->assertMatchesOpenApiResponse($reponse, '/evaluations/{id}/reclamations/{reclamation}/decision', 'post');

        return $reponse;
    }

    private function lister(User $lecteur): TestResponse
    {
        $reponse = $this->asTenant($lecteur)->getJson('/api/reclamations');
        $this->assertMatchesOpenApiResponse($reponse, '/reclamations', 'get');

        return $reponse->assertOk();
    }

    /** @return array<string, list<int>> les destinataires, par type de notification de réclamation */
    private function notifiees(): array
    {
        $parType = [];
        foreach (Notification::query()->withoutGlobalScope('institution')->where('type', 'like', 'reclamation_%')->orderBy('id')->get() as $notification) {
            $parType[(string) $notification->type][] = (int) $notification->user_id;
        }

        return $parType;
    }
}
