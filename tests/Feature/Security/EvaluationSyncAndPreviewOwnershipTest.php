<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Synchroniser les notes, ou prévisualiser une évaluation, exige d'en être
 * propriétaire — comme la publier ou en lire les notes (EvaluationResultsOwnershipTest).
 *
 * ## Le défaut
 *
 * Les trois routes ne vérifiaient que le RÔLE. Tout enseignant de l'établissement :
 *   - poussait vers KLASSCI les notes de l'évaluation d'un collègue, avec SON
 *     jeton, et la déclarait `notes_published` ;
 *   - marquait les copies d'un collègue « synchronisées » sans rien envoyer ;
 *   - lisait les questions d'une évaluation qui n'était pas la sienne.
 *
 * ## Écrire n'est pas lire
 *
 * Synchroniser publie des notes : c'est une MUTATION, gardée par
 * `checkEvaluationOwnership()`, qui exclut les coordinateurs — comme
 * `publish`, dont la route les admet pourtant. Prévisualiser est une LECTURE,
 * gardée par `checkEvaluationReadAccess()` : le coordinateur y garde son accès
 * de supervision, que le service borne aux évaluations terminées.
 *
 * ## Un refus doit précéder l'effet
 *
 * Un 403 rendu APRÈS la mutation ne protège rien. Les cas rouges vérifient donc
 * aussi que l'état n'a pas bougé.
 *
 * ## Jeton porteur RÉEL
 *
 * `Sanctum::actingAs` n'émet pas de Bearer : aucun tenant résolu, le scope
 * d'établissement saute. Voir EvaluationResultsOwnershipTest.
 *
 * @see app/Http/Requests/Concerns/ChecksEvaluationOwnership.php
 */
final class EvaluationSyncAndPreviewOwnershipTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const ENSEIGNANT_PROPRIETAIRE = 4242;

    private const ENSEIGNANT_TIERS = 9999;

    private Institution $institution;

    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        // Aucun appel ne sort : KLASSCI répond « enregistré » à tout.
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['saved' => 1]])]);

        $this->institution = Institution::factory()->create();
        $this->evaluation = Evaluation::factory()->terminee()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => self::ENSEIGNANT_PROPRIETAIRE,
            'klassci_evaluation_id' => 9001,
            'is_published' => true,
            'notes_published' => false,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $this->evaluation->id,
            'institution_id' => $this->institution->id,
            'type' => 'qcm',
        ]);
        EvaluationSubmission::create([
            'evaluation_id' => $this->evaluation->id,
            'klassci_etudiant_id' => 5555,
            'attempt' => 1,
            'status' => 'soumis',
            'started_at' => now(),
            'submitted_at' => now(),
            'note_sur_20' => 12,
            'institution_id' => $this->institution->id,
        ]);
    }

    private function utilisateur(string $role, ?int $klassciEnseignantId): User
    {
        return User::factory()->for($this->institution)->create([
            'role' => $role,
            'klassci_enseignant_id' => $klassciEnseignantId,
            'klassci_token' => 'jeton',
        ]);
    }

    /** @return array<string, TestResponse> Les deux écritures, pour le même acteur. */
    private function lesDeuxSynchronisations(User $acteur): array
    {
        return [
            'sync-klassci' => $this->asTenant($acteur)->postJson("/api/evaluations/{$this->evaluation->id}/sync-klassci"),
            'sync-notes' => $this->asTenant($acteur)->postJson("/api/evaluations/{$this->evaluation->id}/sync-notes"),
        ];
    }

    /** @return array<string, int> */
    private function statuts(array $reponses): array
    {
        return array_map(static fn (TestResponse $r): int => $r->status(), $reponses);
    }

    private function assertRienNABouge(): void
    {
        $this->evaluation->refresh();
        self::assertFalse($this->evaluation->notes_published, 'Les notes ont été déclarées publiées.');
        self::assertSame(
            [false],
            $this->evaluation->submissions()->pluck('synced_to_klassci')->map(fn ($v): bool => (bool) $v)->all(),
            'Des copies ont été marquées synchronisées.',
        );
    }

    public function test_un_enseignant_ne_synchronise_pas_les_notes_d_un_collegue(): void
    {
        $tiers = $this->utilisateur('enseignant', self::ENSEIGNANT_TIERS);

        self::assertSame(
            ['sync-klassci' => 403, 'sync-notes' => 403],
            $this->statuts($this->lesDeuxSynchronisations($tiers)),
        );
        $this->assertRienNABouge();
    }

    public function test_un_coordinateur_ne_synchronise_pas_comme_il_ne_publie_pas(): void
    {
        $coordinateur = $this->utilisateur('coordinateur', null);

        self::assertSame(
            ['sync-klassci' => 403, 'sync-notes' => 403],
            $this->statuts($this->lesDeuxSynchronisations($coordinateur)),
        );
        $this->assertRienNABouge();
    }

    public function test_un_enseignant_sans_identite_klassci_ne_synchronise_pas_une_evaluation_orpheline(): void
    {
        // Orpheline À DESSEIN : sans la garde `!== null`, `null === null`
        // ferait de l'absence d'identité une preuve de propriété.
        $this->evaluation->forceFill(['klassci_enseignant_id' => null])->save();
        $sansIdentite = $this->utilisateur('enseignant', null);

        self::assertSame(
            ['sync-klassci' => 403, 'sync-notes' => 403],
            $this->statuts($this->lesDeuxSynchronisations($sansIdentite)),
        );
        $this->assertRienNABouge();
    }

    public function test_le_proprietaire_synchronise_ses_notes(): void
    {
        $proprietaire = $this->utilisateur('enseignant', self::ENSEIGNANT_PROPRIETAIRE);

        self::assertSame(
            ['sync-klassci' => 200, 'sync-notes' => 200],
            $this->statuts($this->lesDeuxSynchronisations($proprietaire)),
        );
    }

    public function test_l_admin_d_etablissement_synchronise(): void
    {
        $admin = $this->utilisateur('superAdmin', null);

        self::assertSame(
            ['sync-klassci' => 200, 'sync-notes' => 200],
            $this->statuts($this->lesDeuxSynchronisations($admin)),
        );
    }

    public function test_un_enseignant_ne_previsualise_pas_l_evaluation_d_un_collegue(): void
    {
        $tiers = $this->utilisateur('enseignant', self::ENSEIGNANT_TIERS);

        $this->asTenant($tiers)
            ->getJson("/api/evaluations/{$this->evaluation->id}/preview")
            ->assertForbidden()
            ->assertJsonMissingPath('data');
    }

    public function test_la_previsualisation_reste_ouverte_au_proprietaire_au_coordinateur_et_a_l_admin(): void
    {
        $acteurs = [
            'proprietaire' => $this->utilisateur('enseignant', self::ENSEIGNANT_PROPRIETAIRE),
            // L'évaluation est TERMINÉE : le service n'ouvre qu'elles au coordinateur.
            'coordinateur' => $this->utilisateur('coordinateur', null),
            'admin' => $this->utilisateur('superAdmin', null),
        ];

        $statuts = array_map(
            fn (User $acteur): int => $this->asTenant($acteur)
                ->getJson("/api/evaluations/{$this->evaluation->id}/preview")->status(),
            $acteurs,
        );

        self::assertSame(['proprietaire' => 200, 'coordinateur' => 200, 'admin' => 200], $statuts);
    }
}
